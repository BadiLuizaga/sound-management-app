<?php

namespace Modules\Rentals\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Rentals\Models\MaquinariaBloqueo;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Services\CotizadorReserva;
use Modules\Rentals\Services\DisponibilidadService;
use Modules\Rentals\Tests\Support\EscenarioReservas;
use Tests\TestCase;

/**
 * Etapa 4 — paso 2: disponibilidad por intervalo [a, b) con margen de
 * preparación antes y después (C1, D4), bloqueos sin margen, alquileres
 * heredados (D1) y unidades múltiples (pico de uso simultáneo).
 */
class DisponibilidadServiceTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioReservas;

    private DisponibilidadService $disponibilidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->disponibilidad = app(DisponibilidadService::class);
        config(['rentals.margen_preparacion_minutos' => 60]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function libres(int $maquinariaId, string $inicio, string $fin): int
    {
        return $this->disponibilidad->disponibles($maquinariaId, $inicio, $fin);
    }

    public function test_el_margen_por_defecto_es_60_minutos(): void
    {
        $config = require base_path('Modules/Rentals/config/config.php');
        $this->assertSame(60, $config['margen_preparacion_minutos']);
        $this->assertSame(60, $this->disponibilidad->margenMinutos());

        config(['rentals.margen_preparacion_minutos' => -5]);
        $this->assertSame(0, $this->disponibilidad->margenMinutos(), 'Un margen negativo se trata como 0');
    }

    public function test_sin_ocupacion_todas_las_unidades_estan_libres(): void
    {
        $m = $this->equipo(3);
        $r = $this->disponibilidad->consultar([$m->id => 2], '2026-12-01 10:00', '2026-12-01 14:00');

        $this->assertTrue($r->alcanza());
        $this->assertSame(3, $r->disponiblesDe($m->id));
        $this->assertSame([], $r->faltantes());
        $this->assertNull($r->motivo());
    }

    public function test_intervalos_semiabiertos_sin_margen(): void
    {
        config(['rentals.margen_preparacion_minutos' => 0]);
        $m = $this->equipo(1);
        $this->reservaConEquipos([$m->id => 1], '2026-12-01 10:00:00', '2026-12-01 14:00:00');

        $this->assertSame(1, $this->libres($m->id, '2026-12-01 14:00', '2026-12-01 16:00'), 'Empieza justo al terminar');
        $this->assertSame(1, $this->libres($m->id, '2026-12-01 08:00', '2026-12-01 10:00'), 'Termina justo al empezar');
        $this->assertSame(0, $this->libres($m->id, '2026-12-01 13:59', '2026-12-01 16:00'));
        $this->assertSame(0, $this->libres($m->id, '2026-12-01 11:00', '2026-12-01 12:00'), 'Contenida');
        $this->assertSame(0, $this->libres($m->id, '2026-12-01 09:00', '2026-12-01 15:00'), 'La contiene');
    }

    /** Los cuatro ejemplos de §3.2 del diagnóstico (M = 60). */
    public function test_margen_de_60_minutos_antes_y_despues_c1(): void
    {
        $m = $this->equipo(1);
        $this->reservaConEquipos([$m->id => 1], '2026-12-01 10:00:00', '2026-12-01 14:00:00');
        $this->assertSame(1, $this->libres($m->id, '2026-12-01 15:00', '2026-12-01 18:00'), '60 min después: permitida');
        $this->assertSame(0, $this->libres($m->id, '2026-12-01 14:30', '2026-12-01 18:00'), '30 min después: choca');
        $this->assertSame(1, $this->libres($m->id, '2026-12-01 08:00', '2026-12-01 09:00'), '60 min antes: permitida');

        $otro = $this->equipo(1);
        $this->reservaConEquipos([$otro->id => 1], '2026-12-01 15:00:00', '2026-12-01 18:00:00');
        $this->assertSame(0, $this->libres($otro->id, '2026-12-01 10:00', '2026-12-01 14:30'), '30 min antes: choca');
        $this->assertSame(1, $this->libres($otro->id, '2026-12-01 10:00', '2026-12-01 14:00'), 'Justo 60 min antes: permitida');
    }

    public function test_el_margen_es_configurable_y_solo_afecta_consultas_nuevas(): void
    {
        $m = $this->equipo(1);
        $this->reservaConEquipos([$m->id => 1], '2026-12-01 10:00:00', '2026-12-01 14:00:00');

        config(['rentals.margen_preparacion_minutos' => 30]);
        $r = $this->disponibilidad->consultar([$m->id => 1], '2026-12-01 14:30', '2026-12-01 18:00');
        $this->assertSame(30, $r->margenMinutos);
        $this->assertTrue($r->alcanza());

        // La reserva existente conserva su intervalo real: el margen no se guarda en ella
        $this->assertSame('2026-12-01 14:00:00', ReservaEquipo::first()->fecha_fin->toDateTimeString());
    }

    public function test_cuenta_el_pico_de_uso_y_no_la_suma(): void
    {
        $m = $this->equipo(2);
        // Dos reservas consecutivas (60 min entre ambas) que usan una unidad cada una
        $this->reservaConEquipos([$m->id => 1], '2026-12-01 10:00:00', '2026-12-01 11:00:00');
        $this->reservaConEquipos([$m->id => 1], '2026-12-01 12:00:00', '2026-12-01 13:00:00');

        $r = $this->disponibilidad->consultar([$m->id => 1], '2026-12-01 09:00', '2026-12-01 14:00');
        $this->assertSame(1, $r->equipos[$m->id]['ocupadas']);
        $this->assertSame(1, $r->disponiblesDe($m->id));
        $this->assertTrue($r->alcanza());

        $dos = $this->disponibilidad->consultar([$m->id => 2], '2026-12-01 09:00', '2026-12-01 14:00');
        $this->assertFalse($dos->alcanza());
        $this->assertSame(1, $dos->faltantes()[0]['faltan']);
    }

    public function test_unidades_multiples_y_detalle_de_faltantes(): void
    {
        $parlante = $this->equipo(5, nombre: 'Parlante');
        $consola = $this->equipo(1, nombre: 'Consola');
        $this->reservaConEquipos([$parlante->id => 2, $consola->id => 1], '2026-12-01 10:00:00', '2026-12-01 14:00:00');
        $this->reservaConEquipos([$parlante->id => 2], '2026-12-01 12:00:00', '2026-12-01 16:00:00');

        $r = $this->disponibilidad->consultar([$parlante->id => 2, $consola->id => 1], '2026-12-01 13:00', '2026-12-01 15:00');

        $this->assertSame(4, $r->equipos[$parlante->id]['ocupadas']);
        $this->assertSame(1, $r->disponiblesDe($parlante->id));
        $this->assertSame(0, $r->disponiblesDe($consola->id));
        $this->assertFalse($r->alcanza());
        $this->assertCount(2, $r->faltantes());
        $this->assertStringContainsString('Parlante', $r->motivo());
        $this->assertStringContainsString('se necesitan 2, hay 1', $r->motivo());
        $this->assertStringContainsString('Consola', $r->motivo());
    }

    public function test_equipos_liberados_y_reserva_excluida_no_ocupan(): void
    {
        $m = $this->equipo(1);
        $this->reservaConEquipos([$m->id => 1], '2026-12-01 10:00:00', '2026-12-01 14:00:00', Reserva::ESTADO_CANCELADA, estadoEquipos: ReservaEquipo::ESTADO_LIBERADO);
        $this->assertSame(1, $this->libres($m->id, '2026-12-01 10:00', '2026-12-01 14:00'));

        $propia = $this->reservaConEquipos([$m->id => 1], '2026-12-01 10:00:00', '2026-12-01 14:00:00');
        $this->assertSame(0, $this->libres($m->id, '2026-12-01 10:00', '2026-12-01 14:00'));
        $this->assertSame(1, $this->disponibilidad->disponibles($m->id, '2026-12-01 10:00', '2026-12-01 15:00', $propia->id));
    }

    public function test_bloqueos_se_descuentan_sin_margen(): void
    {
        $m = $this->equipo(2);
        $this->bloqueo($m->id, 1, '2026-12-01 08:00:00', '2026-12-01 10:00:00');

        $this->assertSame(2, $this->libres($m->id, '2026-12-01 10:00', '2026-12-01 12:00'), 'Sin margen: empieza al levantarse');
        $this->assertSame(1, $this->libres($m->id, '2026-12-01 09:00', '2026-12-01 12:00'));

        // `hasta` nulo: indefinido
        $this->bloqueo($m->id, 1, '2026-12-02 00:00:00', null);
        $this->assertSame(1, $this->libres($m->id, '2027-06-01 10:00', '2027-06-01 12:00'));

        // Un bloqueo levantado ya no cuenta
        $this->bloqueo($m->id, 1, '2026-12-03 00:00:00', null, MaquinariaBloqueo::ESTADO_LEVANTADO);
        $this->assertSame(1, $this->libres($m->id, '2027-06-01 10:00', '2027-06-01 12:00'));
    }

    public function test_bloqueos_y_reservas_se_combinan_en_el_mismo_momento(): void
    {
        $m = $this->equipo(3);
        $this->bloqueo($m->id, 1, '2026-12-01 11:00:00', '2026-12-01 12:00:00');
        $this->reservaConEquipos([$m->id => 1], '2026-12-01 11:00:00', '2026-12-01 13:00:00');

        $r = $this->disponibilidad->consultar([$m->id => 1], '2026-12-01 10:00', '2026-12-01 14:00');
        $this->assertSame(2, $r->equipos[$m->id]['ocupadas']);
        $this->assertSame(1, $r->disponiblesDe($m->id));
    }

    public function test_alquileres_heredados_ocupan_hasta_su_devolucion_d1(): void
    {
        $m = $this->equipo(1);
        $this->alquilerHeredado([$m->id], '2026-12-01 10:00:00', '2026-12-01 12:00:00', 'activo');

        $this->assertSame(0, $this->libres($m->id, '2026-12-01 11:00', '2026-12-01 13:00'));
        $this->assertSame(0, $this->libres($m->id, '2027-01-15 10:00', '2027-01-15 12:00'), 'Sin devolver: sigue ocupando');
        $this->assertSame(1, $this->libres($m->id, '2026-12-01 07:00', '2026-12-01 09:00'), 'Antes de su inicio, con 60 min de margen');
        $this->assertSame(0, $this->libres($m->id, '2026-12-01 07:00', '2026-12-01 09:30'), 'Margen antes del inicio');

        $atrasado = $this->equipo(1);
        $this->alquilerHeredado([$atrasado->id], '2026-11-01 10:00:00', '2026-11-01 12:00:00', 'atrasado');
        $this->assertSame(0, $this->libres($atrasado->id, '2026-12-01 10:00', '2026-12-01 12:00'));

        $devuelto = $this->equipo(1);
        $this->alquilerHeredado([$devuelto->id], '2026-11-01 10:00:00', '2026-11-01 12:00:00', 'completado');
        $this->alquilerHeredado([$devuelto->id], '2026-11-01 10:00:00', '2026-11-01 12:00:00', 'cancelado');
        $this->assertSame(1, $this->libres($devuelto->id, '2026-12-01 10:00', '2026-12-01 12:00'));
    }

    public function test_equipo_en_mantenimiento_no_tiene_unidades(): void
    {
        $m = $this->equipo(4, 'mantenimiento');
        $r = $this->disponibilidad->consultar([$m->id => 1], '2026-12-01 10:00', '2026-12-01 12:00');

        $this->assertTrue($r->equipos[$m->id]['fuera_de_servicio']);
        $this->assertSame(0, $r->disponiblesDe($m->id));
        $this->assertFalse($r->alcanza());
    }

    public function test_reserva_en_curso_vencida_y_sin_devolver_sigue_ocupando(): void
    {
        Carbon::setTestNow('2026-12-01 18:00:00');
        CarbonImmutable::setTestNow('2026-12-01 18:00:00');

        $m = $this->equipo(1);
        $reserva = $this->reservaConEquipos([$m->id => 1], '2026-12-01 10:00:00', '2026-12-01 14:00:00', Reserva::ESTADO_EN_CURSO);
        $this->assertSame(0, $this->libres($m->id, '2026-12-05 10:00', '2026-12-05 12:00'));

        // Devuelta: deja de ocupar después de su fin
        $reserva->forceFill(['devuelta_at' => '2026-12-01 17:00:00'])->save();
        $this->assertSame(1, $this->libres($m->id, '2026-12-05 10:00', '2026-12-05 12:00'));

        // En curso pero dentro de su horario: ocupa solo su intervalo
        $otro = $this->equipo(1);
        $this->reservaConEquipos([$otro->id => 1], '2026-12-01 16:00:00', '2026-12-01 22:00:00', Reserva::ESTADO_EN_CURSO);
        $this->assertSame(0, $this->libres($otro->id, '2026-12-01 20:00', '2026-12-01 23:00'));
        $this->assertSame(1, $this->libres($otro->id, '2026-12-02 10:00', '2026-12-02 12:00'));
    }

    public function test_bloquear_dentro_de_una_transaccion(): void
    {
        // Sin transacción se rechaza: DisponibilidadSinTransaccionTest
        $m = $this->equipo(1);
        $r = DB::transaction(fn () => $this->disponibilidad->consultar([$m->id => 1], '2026-12-01 10:00', '2026-12-01 12:00', bloquear: true));
        $this->assertTrue($r->alcanza());
    }

    public function test_valida_la_solicitud(): void
    {
        $m = $this->equipo(1);

        $this->assertThrows(fn () => $this->disponibilidad->consultar([$m->id => 1], '2026-12-01 12:00', '2026-12-01 12:00'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->disponibilidad->consultar([$m->id => 1], '2026-12-01 12:00', '2026-12-01 10:00'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->disponibilidad->consultar([], '2026-12-01 10:00', '2026-12-01 12:00'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->disponibilidad->consultar([$m->id => 0], '2026-12-01 10:00', '2026-12-01 12:00'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->disponibilidad->consultar([999999 => 1], '2026-12-01 10:00', '2026-12-01 12:00'), InvalidArgumentException::class);
    }

    public function test_disponibilidad_de_una_cotizacion_de_paquetes(): void
    {
        $parlante = $this->equipo(4, nombre: 'Parlante');
        $consola = $this->equipo(1, nombre: 'Consola');
        $basico = $this->paquete([$parlante->id => 2, $consola->id => 1]);
        $extra = $this->paquete([$parlante->id => 1]);
        $this->reservaConEquipos([$parlante->id => 2], '2026-12-01 10:00:00', '2026-12-01 14:00:00');

        $cotizacion = app(CotizadorReserva::class)->cotizar(
            [['paquete_id' => $basico->id, 'cantidad' => 1], ['paquete_id' => $extra->id, 'cantidad' => 1]],
            '2026-12-01 10:00', '2026-12-01 14:00'
        );
        $this->assertSame([$parlante->id => 3, $consola->id => 1], $cotizacion->requerimientos);

        $r = $this->disponibilidad->paraCotizacion($cotizacion);
        $this->assertFalse($r->alcanza());
        $this->assertSame([['maquinaria_id' => $parlante->id, 'codigo' => $parlante->codigo, 'nombre' => 'Parlante', 'solicitadas' => 3, 'disponibles' => 2, 'faltan' => 1]], $r->faltantes());
        $this->assertSame(1, $r->disponiblesDe($consola->id));
    }
}
