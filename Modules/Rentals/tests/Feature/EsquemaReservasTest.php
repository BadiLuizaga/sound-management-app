<?php

namespace Modules\Rentals\Tests\Feature;

use Database\Factories\UsuarioFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Rentals\Models\Maquinaria;
use Modules\Rentals\Models\MaquinariaBloqueo;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaCargo;
use Modules\Rentals\Models\ReservaEntrega;
use Modules\Rentals\Models\ReservaEntregaDetalle;
use Modules\Rentals\Models\ReservaEvento;
use Modules\Rentals\Models\ReservaIncidencia;
use Tests\TestCase;

/**
 * Etapa 4: esquema final de las reservas y sus modelos (solo estructura y
 * relaciones). Alcance de SOUND POS: sin garantías, sin horas adicionales ni
 * control de retrasos.
 */
class EsquemaReservasTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS_NUEVAS = [
        'reserva_eventos', 'reserva_entregas', 'reserva_entrega_detalles', 'reserva_incidencias',
        'reserva_cargos', 'maquinaria_bloqueos',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ───────────────────────── Ayudantes ─────────────────────────

    private function reserva(array $extra = []): Reserva
    {
        $cliente = UsuarioFactory::new()->cliente()->create();

        return Reserva::create($extra + [
            'codigo' => 'R-' . Str::upper(Str::random(8)),
            'ci_cliente' => $cliente->ci,
            'fecha_inicio' => '2026-12-01 10:00:00',
            'fecha_fin' => '2026-12-01 14:00:00',
            'horas' => 4,
            'total' => '400.00',
        ]);
    }

    private function maquinaria(int $unidades = 2): Maquinaria
    {
        return Maquinaria::create([
            'codigo' => 'EQ-' . Str::upper(Str::random(6)),
            'nombre' => 'Parlante activo',
            'precio_hora' => '50.00',
            'precio_dia' => '300.00',
            'estado' => 'disponible',
            'cantidad_total' => $unidades,
        ]);
    }

    private function assertForeignKey(string $tabla, string $columna, string $destino): void
    {
        $existe = collect(Schema::getForeignKeys($tabla))->contains(
            fn ($fk) => in_array($columna, $fk['columns'], true) && strtolower($fk['foreign_table']) === strtolower($destino)
        );
        $this->assertTrue($existe, "Falta la clave foránea {$tabla}.{$columna} → {$destino}");
    }

    // ───────────────────────── Esquema ─────────────────────────

    public function test_tablas_y_columnas(): void
    {
        foreach (self::TABLAS_NUEVAS as $t) {
            $this->assertTrue(Schema::hasTable($t), "Falta la tabla {$t}");
        }
        $this->assertTrue(Schema::hasColumns('reservas', [
            'canal', 'total_cargos', 'entregada_at', 'devuelta_at', 'liquidada_at', 'lugar_entrega', 'clave_idempotencia',
        ]));
        $this->assertTrue(Schema::hasColumns('maquinaria_bloqueos', ['maquinaria_id', 'cantidad', 'desde', 'hasta', 'motivo', 'estado']));
        $this->assertTrue(Schema::hasColumns('reserva_cargos', ['concepto', 'monto', 'incidencia_id', 'estado']));

        // Eliminado del alcance: garantías y horas adicionales
        $this->assertFalse(Schema::hasTable('reserva_garantias'));
        $this->assertFalse(Schema::hasColumn('pagos', 'concepto'));
        $this->assertFalse(Schema::hasColumn('reserva_cargos', 'horas'));

        // D8: mantenimientos no se modifica
        $this->assertTrue(Schema::hasColumn('mantenimientos', 'idproducto'));
        $this->assertFalse(Schema::hasColumn('mantenimientos', 'maquinaria_id'));
    }

    public function test_claves_foraneas(): void
    {
        $this->assertForeignKey('reserva_eventos', 'reserva_id', 'reservas');
        $this->assertForeignKey('reserva_eventos', 'ci_responsable', 'usuario');
        $this->assertForeignKey('reserva_entregas', 'reserva_id', 'reservas');
        $this->assertForeignKey('reserva_entrega_detalles', 'reserva_entrega_id', 'reserva_entregas');
        $this->assertForeignKey('reserva_entrega_detalles', 'maquinaria_id', 'maquinarias');
        $this->assertForeignKey('reserva_incidencias', 'reserva_id', 'reservas');
        $this->assertForeignKey('reserva_incidencias', 'mantenimiento_id', 'mantenimientos');
        $this->assertForeignKey('reserva_cargos', 'reserva_id', 'reservas');
        $this->assertForeignKey('reserva_cargos', 'incidencia_id', 'reserva_incidencias');
        $this->assertForeignKey('maquinaria_bloqueos', 'maquinaria_id', 'maquinarias');
        $this->assertForeignKey('maquinaria_bloqueos', 'mantenimiento_id', 'mantenimientos');
        $this->assertForeignKey('maquinaria_bloqueos', 'incidencia_id', 'reserva_incidencias');
        $this->assertForeignKey('pagos', 'reserva_id', 'reservas');
    }

    public function test_indices_de_consulta(): void
    {
        $indices = fn (string $t) => collect(Schema::getIndexes($t));

        $this->assertTrue($indices('maquinaria_bloqueos')->pluck('name')->map(fn ($n) => strtolower($n))->contains('maquinaria_bloqueos_disponibilidad_index'));
        $this->assertTrue($indices('reservas')->pluck('name')->map(fn ($n) => strtolower($n))->contains('reservas_clave_idempotencia_unique'));
        $this->assertTrue($indices('reserva_eventos')->pluck('name')->map(fn ($n) => strtolower($n))->contains('reserva_eventos_reserva_fecha_index'));
        $this->assertFalse($indices('pagos')->pluck('name')->contains('pagos_reserva_concepto_index'));
        if (DB::getDriverName() === 'mysql') {
            // La FK pagos.reserva_id conserva su índice simple
            $this->assertTrue($indices('pagos')->contains(fn ($i) => $i['columns'] === ['reserva_id']));
        }
    }

    public function test_valores_por_defecto_y_unicidad(): void
    {
        $r = $this->reserva()->fresh();
        $this->assertNull($r->canal);
        $this->assertSame('0.00', $r->total_cargos);

        $this->reserva(['clave_idempotencia' => 'pos-1']);
        $this->assertThrows(fn () => $this->reserva(['clave_idempotencia' => 'pos-1']), QueryException::class);
    }

    public function test_no_se_admiten_horas_extra_ni_retrasos(): void
    {
        $r = $this->reserva();
        $u = UsuarioFactory::new()->conRol('Encargado')->create();

        // El tipo enumerado ya no tiene esos valores (en ambos motores)
        $this->assertThrows(fn () => ReservaCargo::create(['reserva_id' => $r->id, 'concepto' => 'horas_extra', 'monto' => 50, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'he-1']), QueryException::class);
        $this->assertThrows(fn () => ReservaIncidencia::create(['reserva_id' => $r->id, 'tipo' => 'retraso', 'descripcion' => 'x', 'ci_registrado_por' => $u->ci]), QueryException::class);

        $this->assertFalse(defined(ReservaCargo::class . '::CONCEPTO_HORAS_EXTRA'));
        $this->assertFalse(defined(ReservaIncidencia::class . '::TIPO_RETRASO'));
        $this->assertFalse(class_exists(\Modules\Rentals\Models\ReservaGarantia::class));
    }

    public function test_restricciones_check_en_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->assertTrue(true); // SQLite: las CHECK solo se crean en MySQL (igual que en etapas anteriores)
            return;
        }

        $checks = collect(DB::select('SELECT constraint_name AS n FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()'))
            ->pluck('n')->map(fn ($n) => strtolower($n));
        foreach ([
            'reservas_total_cargos_check', 'reserva_entrega_detalles_cantidad_check',
            'reserva_incidencias_cantidad_check', 'reserva_incidencias_costo_check',
            'reserva_cargos_monto_check',
            'maquinaria_bloqueos_cantidad_check', 'maquinaria_bloqueos_intervalo_check',
        ] as $nombre) {
            $this->assertTrue($checks->contains($nombre), "Falta la restricción {$nombre}");
        }
        foreach (['reserva_cargos_horas_check', 'pagos_concepto_garantia_check', 'reserva_garantias_monto_check', 'reserva_garantias_dinero_check'] as $eliminada) {
            $this->assertFalse($checks->contains($eliminada), "La restricción {$eliminada} debía eliminarse");
        }

        $r = $this->reserva();
        $u = UsuarioFactory::new()->conRol('Encargado')->create();
        $m = $this->maquinaria();

        $this->assertThrows(fn () => ReservaCargo::create(['reserva_id' => $r->id, 'concepto' => 'otro', 'monto' => 0, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'c-4']), QueryException::class);
        $this->assertThrows(fn () => MaquinariaBloqueo::create(['maquinaria_id' => $m->id, 'cantidad' => 1, 'desde' => '2026-12-02 10:00:00', 'hasta' => '2026-12-02 09:00:00', 'motivo' => 'dano', 'ci_registrado_por' => $u->ci]), QueryException::class);
        $this->assertThrows(fn () => DB::table('reservas')->where('id', $r->id)->update(['total_cargos' => -1]), QueryException::class);
    }

    // ───────────────────────── Modelos ─────────────────────────

    public function test_modelos_y_relaciones(): void
    {
        $r = $this->reserva(['canal' => Reserva::CANAL_POS]);
        $m = $this->maquinaria(3);
        $u = UsuarioFactory::new()->conRol('Encargado')->create();

        $evento = ReservaEvento::create(['reserva_id' => $r->id, 'tipo' => 'creada', 'estado_nuevo' => 'pendiente', 'ci_responsable' => $u->ci, 'datos' => ['canal' => 'pos']]);

        $entrega = ReservaEntrega::create(['reserva_id' => $r->id, 'tipo' => ReservaEntrega::TIPO_DEVOLUCION, 'fecha' => '2026-12-01 14:10:00', 'ci_responsable' => $u->ci, 'clave_idempotencia' => 'dev-1']);
        ReservaEntregaDetalle::create(['reserva_entrega_id' => $entrega->id, 'maquinaria_id' => $m->id, 'cantidad' => 2, 'estado_equipo' => ReservaEntregaDetalle::ESTADO_BUENO]);
        ReservaEntregaDetalle::create(['reserva_entrega_id' => $entrega->id, 'maquinaria_id' => $m->id, 'cantidad' => 1, 'estado_equipo' => ReservaEntregaDetalle::ESTADO_DANADO]);

        $incidencia = ReservaIncidencia::create(['reserva_id' => $r->id, 'reserva_entrega_id' => $entrega->id, 'maquinaria_id' => $m->id, 'tipo' => ReservaIncidencia::TIPO_DANO, 'descripcion' => 'Rejilla rota', 'costo_estimado' => '80.00', 'ci_registrado_por' => $u->ci]);

        $cargo = ReservaCargo::create(['reserva_id' => $r->id, 'concepto' => ReservaCargo::CONCEPTO_DANO, 'monto' => '80.00', 'incidencia_id' => $incidencia->id, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'car-1']);
        MaquinariaBloqueo::create(['maquinaria_id' => $m->id, 'cantidad' => 1, 'desde' => '2026-12-01 14:10:00', 'motivo' => MaquinariaBloqueo::MOTIVO_DANO, 'incidencia_id' => $incidencia->id, 'ci_registrado_por' => $u->ci]);

        $r->refresh();
        $this->assertSame(Reserva::CANAL_POS, $r->canal);
        $this->assertCount(1, $r->eventos);
        $this->assertSame(['canal' => 'pos'], $r->eventos->first()->datos);
        $this->assertCount(1, $r->entregas);
        $this->assertSame(3, (int) $r->entregas->first()->detalles->sum('cantidad'));
        $this->assertCount(1, $r->incidencias);
        $this->assertCount(1, $r->cargos);
        $this->assertSame('80.00', $r->cargos->first()->monto);

        $this->assertCount(1, $incidencia->cargos);
        $this->assertCount(1, $incidencia->bloqueos);
        $this->assertCount(1, $m->fresh()->bloqueos);
        $this->assertNull($m->bloqueos->first()->hasta); // indefinido

        // El historial es de solo inserción y los cargos no se eliminan
        $this->assertThrows(fn () => $evento->update(['tipo' => 'otro']), \LogicException::class);
        $this->assertThrows(fn () => $evento->delete(), \LogicException::class);
        $this->assertThrows(fn () => $cargo->delete(), \LogicException::class);
    }

    public function test_una_reserva_con_historial_no_se_puede_borrar(): void
    {
        $r = $this->reserva();
        $u = UsuarioFactory::new()->conRol('Encargado')->create();
        ReservaEvento::create(['reserva_id' => $r->id, 'tipo' => 'creada', 'ci_responsable' => $u->ci]);

        // FK restrict: una reserva con historial no se borra (ni en cascada)
        $this->assertThrows(fn () => DB::table('reservas')->where('id', $r->id)->delete(), QueryException::class);
    }
}
