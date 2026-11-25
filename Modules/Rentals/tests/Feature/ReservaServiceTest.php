<?php

namespace Modules\Rentals\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\UsuarioFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Aplicadores\AplicadorReserva;
use Modules\Finance\Models\Pago;
use Modules\Rentals\Enums\EstadoReserva;
use Modules\Rentals\Exceptions\CotizacionInvalida;
use Modules\Rentals\Exceptions\TransicionReservaInvalida;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Models\ReservaEvento;
use Modules\Rentals\Services\ReservaService;
use Modules\Rentals\Tests\Support\EscenarioReservas;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 4 — paso 3: ReservaService (crear, confirmar automáticamente,
 * rechazar, cancelar y vencer), en SQLite y MySQL.
 *
 * Incluye la secuencia equivalente a la prueba de concurrencia, para SQLite:
 * la segunda confirmación, ejecutada después de la primera, ve las unidades
 * ya reservadas. La concurrencia real está en ConcurrenciaReservasMySqlTest.
 */
class ReservaServiceTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioReservas;

    private ReservaService $reservas;
    private Usuario $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-11-30 09:00:00');
        CarbonImmutable::setTestNow('2026-11-30 09:00:00');
        config(['rentals.margen_preparacion_minutos' => 60, 'rentals.expiracion_pendiente_minutos' => 15]);
        $this->reservas = app(ReservaService::class);
        $this->vendedor = UsuarioFactory::new()->conRol('Vendedor')->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function crear(int $paqueteId, string $inicio, string $fin, int $cantidad = 1, ?string $clave = null, ?Usuario $quien = null, ?int $ciCliente = null, string $canal = Reserva::CANAL_POS): Reserva
    {
        $quien ??= $this->vendedor;

        return $this->reservas->crear(
            $quien,
            $ciCliente ?? UsuarioFactory::new()->cliente()->create()->ci,
            [['paquete_id' => $paqueteId, 'cantidad' => $cantidad]],
            $inicio,
            $fin,
            $canal,
            $clave ?? 'pos-' . Str::random(12),
        );
    }

    private function tipos(Reserva $r): array
    {
        return ReservaEvento::where('reserva_id', $r->id)->orderBy('id')->pluck('tipo')->all();
    }

    // ───────────────────────── Crear y confirmar ─────────────────────────

    public function test_crear_confirma_automaticamente_sin_ningun_pago(): void
    {
        $parlante = $this->equipo(4);
        $consola = $this->equipo(1);
        $p = $this->paquete([$parlante->id => 2, $consola->id => 1], '120.50');

        $r = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 14:00');

        $this->assertSame(Reserva::ESTADO_CONFIRMADA, $r->estado);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $r->fresh()->estado_pago, 'Confirmar no depende del pago');
        $this->assertSame(0, Pago::count());
        $this->assertNotNull($r->confirmada_at);
        $this->assertNull($r->expira_at);
        $this->assertSame(Reserva::CANAL_POS, $r->canal);
        $this->assertSame(4, $r->horas);
        $this->assertSame('482.00', $r->fresh()->total); // 120.50 × 4 h

        // Precio congelado en reserva_paquetes
        $fila = $r->paquetes()->first();
        $this->assertSame('120.50', $fila->precio_hora);
        $this->assertSame('482.00', $fila->subtotal);

        // Unidades reservadas con el intervalo real
        $equipos = ReservaEquipo::where('reserva_id', $r->id)->orderBy('maquinaria_id')->get();
        $this->assertSame([[$parlante->id, 2], [$consola->id, 1]], $equipos->map(fn ($e) => [$e->maquinaria_id, $e->cantidad])->all());
        $this->assertSame('2026-12-01 10:00:00', $equipos[0]->fecha_inicio->toDateTimeString());
        $this->assertSame('2026-12-01 14:00:00', $equipos[0]->fecha_fin->toDateTimeString());
        $this->assertTrue($equipos->every(fn ($e) => $e->estado === ReservaEquipo::ESTADO_RESERVADO));

        $this->assertSame([ReservaEvento::CREADA, ReservaEvento::CONFIRMADA], $this->tipos($r));
        $this->assertTrue(Bitacora::where('accion', 'RESERVA_CONFIRMADA')->where('registro_id', $r->id)->exists());
    }

    public function test_sin_disponibilidad_se_rechaza_al_instante_con_los_faltantes_d2(): void
    {
        $m = $this->equipo(2, nombre: 'Subwoofer');
        $p = $this->paquete([$m->id => 2]);
        $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 14:00');

        $r = $this->crear($p->id, '2026-12-01 12:00', '2026-12-01 16:00');

        $this->assertSame(Reserva::ESTADO_RECHAZADA, $r->estado);
        $this->assertStringContainsString('Subwoofer', $r->motivo_cancelacion);
        $this->assertStringContainsString('se necesitan 2, hay 0', $r->motivo_cancelacion);
        $this->assertSame(0, ReservaEquipo::where('reserva_id', $r->id)->count());

        $evento = ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::RECHAZADA)->first();
        $this->assertSame('pendiente', $evento->estado_anterior);
        $this->assertSame('rechazada', $evento->estado_nuevo);
        $this->assertSame(2, $evento->datos['faltantes'][0]['faltan']);
    }

    public function test_el_margen_de_60_minutos_se_respeta_al_confirmar(): void
    {
        $m = $this->equipo(1);
        $p = $this->paquete([$m->id => 1]);
        $this->assertSame('confirmada', $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 14:00')->estado);

        $this->assertSame('rechazada', $this->crear($p->id, '2026-12-01 14:30', '2026-12-01 16:30')->estado, '30 min después');
        $this->assertSame('confirmada', $this->crear($p->id, '2026-12-01 15:00', '2026-12-01 17:00')->estado, '60 min después');
        $this->assertSame('confirmada', $this->crear($p->id, '2026-12-01 08:00', '2026-12-01 09:00')->estado, '60 min antes');
    }

    public function test_secuencia_equivalente_a_la_concurrencia_para_sqlite(): void
    {
        // Insuficientes: la segunda, confirmada después, ve las unidades ya reservadas
        $m = $this->equipo(2);
        $p = $this->paquete([$m->id => 2]);
        $a = $this->reservaPendiente($p, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00');
        $b = $this->reservaPendiente($p, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00');
        $this->assertSame('confirmada', $this->reservas->confirmar($a)->estado);
        $this->assertSame('rechazada', $this->reservas->confirmar($b)->estado);
        $this->assertSame(2, (int) ReservaEquipo::where('maquinaria_id', $m->id)->where('estado', 'reservado')->sum('cantidad'));

        // Suficientes: las dos
        $m4 = $this->equipo(4);
        $p4 = $this->paquete([$m4->id => 2]);
        $this->assertSame('confirmada', $this->reservas->confirmar($this->reservaPendiente($p4, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00'))->estado);
        $this->assertSame('confirmada', $this->reservas->confirmar($this->reservaPendiente($p4, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00'))->estado);

        // Margen: 30 min entre ambas
        $m1 = $this->equipo(1);
        $p1 = $this->paquete([$m1->id => 1]);
        $this->assertSame('confirmada', $this->reservas->confirmar($this->reservaPendiente($p1, 1, '2026-12-10 10:00:00', '2026-12-10 12:00:00'))->estado);
        $this->assertSame('rechazada', $this->reservas->confirmar($this->reservaPendiente($p1, 1, '2026-12-10 12:30:00', '2026-12-10 14:30:00'))->estado);
    }

    public function test_confirmar_es_idempotente(): void
    {
        $m = $this->equipo(3);
        $p = $this->paquete([$m->id => 1]);
        $r = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00');

        $otra = $this->reservas->confirmar($r->id);

        $this->assertSame('confirmada', $otra->estado);
        $this->assertSame(1, ReservaEquipo::where('reserva_id', $r->id)->count());
        $this->assertSame(1, ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::CONFIRMADA)->count());
    }

    public function test_crear_es_idempotente_por_clave(): void
    {
        $p = $this->paquete([$this->equipo(5)->id => 1]);
        $cliente = UsuarioFactory::new()->cliente()->create();

        $r1 = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00', clave: 'pos-doble', ciCliente: $cliente->ci);
        $r2 = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00', clave: 'pos-doble', ciCliente: $cliente->ci);

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame(1, Reserva::where('clave_idempotencia', 'pos-doble')->count());
        $this->assertSame(1, ReservaEquipo::where('reserva_id', $r1->id)->count());

        $otro = UsuarioFactory::new()->conRol('Vendedor')->create();
        $this->assertThrows(fn () => $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00', clave: 'pos-doble', quien: $otro), \DomainException::class, 'ya fue registrada por otro usuario');
    }

    public function test_crear_valida_la_solicitud_sin_dejar_nada_registrado(): void
    {
        $p = $this->paquete([$this->equipo()->id => 1]);
        $ci = UsuarioFactory::new()->cliente()->create()->ci;
        $linea = [['paquete_id' => $p->id]];

        $this->assertThrows(fn () => $this->reservas->crear($this->vendedor, $ci, $linea, '2026-12-01 10:00', '2026-12-01 12:00', 'telefono', 'c-1'), \DomainException::class, 'Canal');
        $this->assertThrows(fn () => $this->reservas->crear($this->vendedor, 99999999, $linea, '2026-12-01 10:00', '2026-12-01 12:00', 'pos', 'c-2'), \DomainException::class, 'cliente no existe');
        $this->assertThrows(fn () => $this->reservas->crear($this->vendedor, $ci, $linea, '2026-12-01 10:00', '2026-12-01 12:00', 'pos', '  '), \DomainException::class, 'idempotencia');
        $this->assertThrows(fn () => $this->reservas->crear($this->vendedor, $ci, $linea, '2026-11-30 08:00', '2026-11-30 10:00', 'pos', 'c-3'), CotizacionInvalida::class, 'pasado');
        $this->assertThrows(fn () => $this->reservas->crear($this->vendedor, $ci, $linea, '2026-12-01 10:00', '2026-12-01 11:30', 'pos', 'c-4'), CotizacionInvalida::class, 'horas completas');
        // Portal: el cliente solo reserva a su nombre
        $this->assertThrows(fn () => $this->reservas->crear($this->vendedor, $ci, $linea, '2026-12-01 10:00', '2026-12-01 12:00', 'portal', 'c-5'), \DomainException::class, 'a su nombre');

        $this->assertSame(0, Reserva::count());
        $this->assertSame(0, ReservaEvento::count());
    }

    public function test_el_cliente_reserva_a_su_nombre_desde_el_portal(): void
    {
        $p = $this->paquete([$this->equipo()->id => 1]);
        $cliente = UsuarioFactory::new()->cliente()->create();

        $r = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00', quien: $cliente, ciCliente: $cliente->ci, canal: Reserva::CANAL_PORTAL);

        $this->assertSame('confirmada', $r->estado);
        $this->assertSame((string) $cliente->ci, (string) $r->ci_registrado_por);
    }

    public function test_un_pago_no_confirma_ni_bloquea_equipos(): void
    {
        $m = $this->equipo(1);
        $p = $this->paquete([$m->id => 1], '100.00');
        $r = $this->reservaPendiente($p, 1, '2026-12-01 10:00:00', '2026-12-01 12:00:00');

        DB::table('pagos')->insert([
            'uuid' => (string) Str::uuid(), 'canal' => 'presencial', 'proveedor' => 'manual',
            'metodo_pago_id' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO), 'estado' => 'recibido',
            'estado_aplicacion' => 'aplicado', 'monto' => '200.00', 'reserva_id' => $r->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(AplicadorReserva::class)->recalcular($r->id);

        $r->refresh();
        $this->assertSame(Reserva::PAGO_PAGADO, $r->estado_pago);
        $this->assertSame(Reserva::ESTADO_PENDIENTE, $r->estado, 'Pagar no confirma');
        $this->assertSame(0, ReservaEquipo::where('reserva_id', $r->id)->count(), 'Pagar no bloquea equipos');
    }

    // ───────────────────────── Cancelar ─────────────────────────

    public function test_cancelar_libera_las_unidades_y_no_toca_los_pagos(): void
    {
        $m = $this->equipo(1);
        $p = $this->paquete([$m->id => 1]);
        $r = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00');
        $r->forceFill(['estado_pago' => Reserva::PAGO_PARCIAL])->save();
        $encargado = $this->encargado();

        $this->assertThrows(fn () => $this->reservas->cancelar($r, $encargado, '  '), \DomainException::class, 'motivo');

        $c = $this->reservas->cancelar($r, $encargado, 'El cliente desistió');

        $this->assertSame(Reserva::ESTADO_CANCELADA, $c->estado);
        $this->assertSame('El cliente desistió', $c->motivo_cancelacion);
        $this->assertNotNull($c->cancelada_at);
        $this->assertSame(Reserva::PAGO_PARCIAL, $c->fresh()->estado_pago, 'D7: los pagos siguen su circuito');
        $equipo = ReservaEquipo::where('reserva_id', $r->id)->first();
        $this->assertSame(ReservaEquipo::ESTADO_LIBERADO, $equipo->estado);
        $this->assertNotNull($equipo->liberado_at);

        $evento = ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::CANCELADA)->first();
        $this->assertSame(1, $evento->datos['unidades_liberadas']);
        $this->assertSame('parcial', $evento->datos['estado_pago']);
        $this->assertSame((string) $encargado->ci, (string) $evento->ci_responsable);

        // Las unidades vuelven a estar disponibles
        $this->assertSame('confirmada', $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00')->estado);

        // Idempotente
        $this->reservas->cancelar($r, $encargado, 'otra vez');
        $this->assertSame(1, ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::CANCELADA)->count());
    }

    public function test_transiciones_no_permitidas(): void
    {
        $m = $this->equipo(1);
        $p = $this->paquete([$m->id => 1]);
        $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00');
        $rechazada = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00');
        $this->assertSame('rechazada', $rechazada->estado);

        $this->assertThrows(fn () => $this->reservas->confirmar($rechazada), TransicionReservaInvalida::class);
        $this->assertThrows(fn () => $this->reservas->cancelar($rechazada, $this->encargado(), 'x'), TransicionReservaInvalida::class);

        $enCurso = $this->reservaConEquipos([$this->equipo(1)->id => 1], '2026-12-02 10:00:00', '2026-12-02 12:00:00', Reserva::ESTADO_EN_CURSO);
        $this->assertThrows(fn () => $this->reservas->cancelar($enCurso, $this->encargado(), 'x'), TransicionReservaInvalida::class, 'en_curso');
    }

    // ───────────────────────── Vencer ─────────────────────────

    public function test_una_pendiente_vencida_expira_al_intentar_confirmarla(): void
    {
        $p = $this->paquete([$this->equipo(1)->id => 1]);
        $r = $this->reservaPendiente($p, 1, '2026-12-01 10:00:00', '2026-12-01 12:00:00', ['expira_at' => '2026-11-30 08:59:00']);

        $this->assertSame('expirada', $this->reservas->confirmar($r)->estado);
        $this->assertSame(0, ReservaEquipo::where('reserva_id', $r->id)->count());
        $this->assertSame([ReservaEvento::EXPIRADA], $this->tipos($r));
    }

    public function test_tarea_programada_vence_solo_las_pendientes_vencidas(): void
    {
        $p = $this->paquete([$this->equipo(5)->id => 1]);
        $vencida = $this->reservaPendiente($p, 1, '2026-12-01 10:00:00', '2026-12-01 12:00:00', ['expira_at' => '2026-11-30 08:45:00']);
        $vigente = $this->reservaPendiente($p, 1, '2026-12-01 10:00:00', '2026-12-01 12:00:00', ['expira_at' => '2026-11-30 09:10:00']);
        $confirmada = $this->crear($p->id, '2026-12-01 10:00', '2026-12-01 12:00');

        $this->artisan('reservas:expirar')->expectsOutputToContain('Reservas vencidas: 1')->assertExitCode(0);

        $this->assertSame('expirada', $vencida->fresh()->estado);
        $this->assertSame('pendiente', $vigente->fresh()->estado);
        $this->assertSame('confirmada', $confirmada->fresh()->estado);

        // Una segunda ejecución no hace nada
        $this->assertSame(0, $this->reservas->expirarVencidas());

        $programada = collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'reservas:expirar'));
        $this->assertTrue($programada, 'reservas:expirar debe estar programada');
    }

    public function test_la_pendiente_recien_creada_lleva_plazo_de_seguridad(): void
    {
        // Si la confirmación no llega a ejecutarse, la reserva queda pendiente con su plazo
        $p = $this->paquete([$this->equipo(1)->id => 1]);
        $servicio = new class(app(\Modules\Rentals\Services\CotizadorReserva::class), app(\Modules\Rentals\Services\DisponibilidadService::class)) extends ReservaService {
            public function confirmar(Reserva|int $reserva, ?Usuario $responsable = null): Reserva
            {
                return Reserva::findOrFail($reserva instanceof Reserva ? $reserva->id : $reserva);
            }
        };

        $r = $servicio->crear($this->vendedor, UsuarioFactory::new()->cliente()->create()->ci, [['paquete_id' => $p->id]], '2026-12-01 10:00', '2026-12-01 12:00', 'erp', 'erp-1');

        $this->assertSame('pendiente', $r->estado);
        $this->assertSame('2026-11-30 09:15:00', $r->expira_at->toDateTimeString());
        $this->assertSame(0, ReservaEquipo::where('reserva_id', $r->id)->count());
    }

    // ───────────────────────── Estados ─────────────────────────

    public function test_mapa_de_transiciones(): void
    {
        $this->assertTrue(EstadoReserva::Pendiente->puedePasarA(EstadoReserva::Confirmada));
        $this->assertTrue(EstadoReserva::Pendiente->puedePasarA(EstadoReserva::Rechazada));
        $this->assertTrue(EstadoReserva::Pendiente->puedePasarA(EstadoReserva::Expirada));
        $this->assertTrue(EstadoReserva::Confirmada->puedePasarA(EstadoReserva::Cancelada));
        $this->assertTrue(EstadoReserva::Confirmada->puedePasarA(EstadoReserva::EnCurso));
        $this->assertTrue(EstadoReserva::EnCurso->puedePasarA(EstadoReserva::Finalizada));
        $this->assertFalse(EstadoReserva::EnCurso->puedePasarA(EstadoReserva::Cancelada));
        $this->assertFalse(EstadoReserva::Confirmada->puedePasarA(EstadoReserva::Pendiente));
        foreach ([EstadoReserva::Finalizada, EstadoReserva::Cancelada, EstadoReserva::Expirada, EstadoReserva::Rechazada] as $final) {
            $this->assertTrue($final->esFinal());
        }
        // Coincide con el enum de la base
        $this->assertSame(['pendiente', 'confirmada', 'en_curso', 'finalizada', 'cancelada', 'expirada', 'rechazada'], array_map(fn ($e) => $e->value, EstadoReserva::cases()));
    }
}
