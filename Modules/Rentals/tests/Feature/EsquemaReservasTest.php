<?php

namespace Modules\Rentals\Tests\Feature;

use Database\Factories\UsuarioFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Finance\Models\Pago;
use Modules\Rentals\Models\Maquinaria;
use Modules\Rentals\Models\MaquinariaBloqueo;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaCargo;
use Modules\Rentals\Models\ReservaEntrega;
use Modules\Rentals\Models\ReservaEntregaDetalle;
use Modules\Rentals\Models\ReservaEvento;
use Modules\Rentals\Models\ReservaGarantia;
use Modules\Rentals\Models\ReservaIncidencia;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 4 — paso 1: esquema de las 8 migraciones nuevas y sus modelos
 * (solo estructura y relaciones; la lógica llega en los pasos siguientes).
 */
class EsquemaReservasTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS_NUEVAS = [
        'reserva_eventos', 'reserva_entregas', 'reserva_entrega_detalles', 'reserva_incidencias',
        'reserva_garantias', 'reserva_cargos', 'maquinaria_bloqueos',
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

    private function pagoCrudo(array $extra = []): int
    {
        return DB::table('pagos')->insertGetId($extra + [
            'uuid' => (string) Str::uuid(),
            'canal' => 'presencial',
            'proveedor' => 'manual',
            'metodo_pago_id' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'estado' => 'recibido',
            'monto' => '150.00',
            'created_at' => now(),
            'updated_at' => now(),
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

    public function test_tablas_y_columnas_nuevas(): void
    {
        foreach (self::TABLAS_NUEVAS as $t) {
            $this->assertTrue(Schema::hasTable($t), "Falta la tabla {$t}");
        }
        $this->assertTrue(Schema::hasColumns('reservas', [
            'canal', 'total_cargos', 'entregada_at', 'devuelta_at', 'liquidada_at', 'lugar_entrega', 'clave_idempotencia',
        ]));
        $this->assertTrue(Schema::hasColumn('pagos', 'concepto'));
        $this->assertTrue(Schema::hasColumns('reserva_garantias', [
            'tipo', 'monto', 'estado', 'pago_id', 'reembolso_id', 'monto_retenido', 'incidencia_id', 'clave_idempotencia',
        ]));
        $this->assertTrue(Schema::hasColumns('maquinaria_bloqueos', ['maquinaria_id', 'cantidad', 'desde', 'hasta', 'motivo', 'estado']));
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
        $this->assertForeignKey('reserva_garantias', 'reserva_id', 'reservas');
        $this->assertForeignKey('reserva_garantias', 'pago_id', 'pagos');
        $this->assertForeignKey('reserva_garantias', 'reembolso_id', 'reembolsos');
        $this->assertForeignKey('reserva_garantias', 'incidencia_id', 'reserva_incidencias');
        $this->assertForeignKey('reserva_cargos', 'reserva_id', 'reservas');
        $this->assertForeignKey('reserva_cargos', 'incidencia_id', 'reserva_incidencias');
        $this->assertForeignKey('maquinaria_bloqueos', 'maquinaria_id', 'maquinarias');
        $this->assertForeignKey('maquinaria_bloqueos', 'mantenimiento_id', 'mantenimientos');
        $this->assertForeignKey('maquinaria_bloqueos', 'incidencia_id', 'reserva_incidencias');
    }

    public function test_indices_de_consulta(): void
    {
        $indices = fn (string $t) => collect(Schema::getIndexes($t))->pluck('name')->map(fn ($n) => strtolower($n));

        $this->assertTrue($indices('maquinaria_bloqueos')->contains('maquinaria_bloqueos_disponibilidad_index'));
        $this->assertTrue($indices('pagos')->contains('pagos_reserva_concepto_index'));
        $this->assertTrue($indices('reservas')->contains('reservas_clave_idempotencia_unique'));
        $this->assertTrue($indices('reserva_eventos')->contains('reserva_eventos_reserva_fecha_index'));
        $this->assertTrue($indices('reserva_garantias')->contains('reserva_garantias_pago_unique'));
    }

    public function test_valores_por_defecto(): void
    {
        $r = $this->reserva()->fresh();
        $this->assertNull($r->canal);
        $this->assertSame('0.00', $r->total_cargos);

        $pago = Pago::find($this->pagoCrudo());
        $this->assertSame(Pago::CONCEPTO_ALQUILER, $pago->concepto);
    }

    public function test_unicidad(): void
    {
        $this->reserva(['clave_idempotencia' => 'pos-1']);
        $this->assertThrows(fn () => $this->reserva(['clave_idempotencia' => 'pos-1']), QueryException::class);

        $r = $this->reserva();
        $vendedor = UsuarioFactory::new()->conRol('Vendedor')->create();
        $pagoId = $this->pagoCrudo(['reserva_id' => $r->id, 'concepto' => 'garantia']);
        $base = ['reserva_id' => $r->id, 'tipo' => 'dinero', 'monto' => '150.00', 'pago_id' => $pagoId, 'ci_registrado_por' => $vendedor->ci];
        ReservaGarantia::create($base + ['clave_idempotencia' => 'g-1']);
        // Un pago respalda como máximo una garantía
        $this->assertThrows(fn () => ReservaGarantia::create($base + ['clave_idempotencia' => 'g-2']), QueryException::class);
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
            'reserva_garantias_monto_check', 'reserva_garantias_dinero_check',
            'reserva_cargos_monto_check', 'reserva_cargos_horas_check',
            'maquinaria_bloqueos_cantidad_check', 'maquinaria_bloqueos_intervalo_check',
            'pagos_concepto_garantia_check',
        ] as $nombre) {
            $this->assertTrue($checks->contains($nombre), "Falta la restricción {$nombre}");
        }

        $r = $this->reserva();
        $u = UsuarioFactory::new()->conRol('Encargado')->create();
        $m = $this->maquinaria();

        // Una garantía sin reserva se rechaza
        $this->assertThrows(fn () => $this->pagoCrudo(['concepto' => 'garantia']), QueryException::class);
        // Garantía en dinero con monto 0, o retenido mayor que el monto
        $this->assertThrows(fn () => ReservaGarantia::create(['reserva_id' => $r->id, 'tipo' => 'dinero', 'monto' => 0, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'c-1']), QueryException::class);
        $this->assertThrows(fn () => ReservaGarantia::create(['reserva_id' => $r->id, 'tipo' => 'dinero', 'monto' => 100, 'monto_retenido' => 101, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'c-2']), QueryException::class);
        // Horas extra sin horas; cargo de 0
        $this->assertThrows(fn () => ReservaCargo::create(['reserva_id' => $r->id, 'concepto' => 'horas_extra', 'monto' => 50, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'c-3']), QueryException::class);
        $this->assertThrows(fn () => ReservaCargo::create(['reserva_id' => $r->id, 'concepto' => 'otro', 'monto' => 0, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'c-4']), QueryException::class);
        // Bloqueo con intervalo invertido
        $this->assertThrows(fn () => MaquinariaBloqueo::create(['maquinaria_id' => $m->id, 'cantidad' => 1, 'desde' => '2026-12-02 10:00:00', 'hasta' => '2026-12-02 09:00:00', 'motivo' => 'dano', 'ci_registrado_por' => $u->ci]), QueryException::class);
        // total_cargos negativo
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

        $pagoId = $this->pagoCrudo(['reserva_id' => $r->id, 'concepto' => Pago::CONCEPTO_GARANTIA, 'monto' => '200.00']);
        ReservaGarantia::create(['reserva_id' => $r->id, 'tipo' => ReservaGarantia::TIPO_DINERO, 'monto' => '200.00', 'pago_id' => $pagoId, 'estado' => ReservaGarantia::ESTADO_RETENIDA, 'monto_retenido' => '80.00', 'incidencia_id' => $incidencia->id, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'gar-1']);
        ReservaGarantia::create(['reserva_id' => $r->id, 'tipo' => ReservaGarantia::TIPO_DOCUMENTO, 'descripcion' => 'CI original', 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'gar-2']);

        $cargo = ReservaCargo::create(['reserva_id' => $r->id, 'concepto' => ReservaCargo::CONCEPTO_DANO, 'monto' => '80.00', 'incidencia_id' => $incidencia->id, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'car-1']);
        MaquinariaBloqueo::create(['maquinaria_id' => $m->id, 'cantidad' => 1, 'desde' => '2026-12-01 14:10:00', 'motivo' => MaquinariaBloqueo::MOTIVO_DANO, 'incidencia_id' => $incidencia->id, 'ci_registrado_por' => $u->ci]);

        $r->refresh();
        $this->assertSame(Reserva::CANAL_POS, $r->canal);
        $this->assertCount(1, $r->eventos);
        $this->assertSame(['canal' => 'pos'], $r->eventos->first()->datos);
        $this->assertCount(1, $r->entregas);
        $this->assertSame(3, (int) $r->entregas->first()->detalles->sum('cantidad'));
        $this->assertCount(1, $r->incidencias);
        $this->assertCount(2, $r->garantias);
        $this->assertCount(1, $r->cargos);

        $garantia = $r->garantias->firstWhere('tipo', 'dinero');
        $this->assertSame(Pago::CONCEPTO_GARANTIA, $garantia->pago->concepto);
        $this->assertSame('80.00', $garantia->monto_retenido);
        $this->assertTrue($garantia->incidencia->is($incidencia));

        $this->assertCount(1, $incidencia->cargos);
        $this->assertCount(1, $incidencia->bloqueos);
        $this->assertCount(1, $m->fresh()->bloqueos);
        $this->assertNull($m->bloqueos->first()->hasta); // indefinido

        // El historial es de solo inserción y los cargos no se eliminan
        $this->assertThrows(fn () => $evento->update(['tipo' => 'otro']), \LogicException::class);
        $this->assertThrows(fn () => $evento->delete(), \LogicException::class);
        $this->assertThrows(fn () => $cargo->delete(), \LogicException::class);
    }

    public function test_las_reservas_y_los_pagos_con_historial_no_se_pueden_borrar(): void
    {
        $r = $this->reserva();
        $u = UsuarioFactory::new()->conRol('Encargado')->create();
        ReservaEvento::create(['reserva_id' => $r->id, 'tipo' => 'creada', 'ci_responsable' => $u->ci]);

        // FK restrict: una reserva con historial no se borra (ni en cascada)
        $this->assertThrows(fn () => DB::table('reservas')->where('id', $r->id)->delete(), QueryException::class);

        $pagoId = $this->pagoCrudo(['reserva_id' => $r->id, 'concepto' => 'garantia']);
        ReservaGarantia::create(['reserva_id' => $r->id, 'tipo' => 'dinero', 'monto' => '150.00', 'pago_id' => $pagoId, 'ci_registrado_por' => $u->ci, 'clave_idempotencia' => 'g-borrar']);
        $this->assertThrows(fn () => DB::table('pagos')->where('id', $pagoId)->delete(), QueryException::class);
    }
}
