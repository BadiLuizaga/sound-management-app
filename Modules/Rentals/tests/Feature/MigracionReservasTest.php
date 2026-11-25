<?php

namespace Modules\Rentals\Tests\Feature;

use Database\Factories\UsuarioFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 4: sus 10 migraciones (8 del paso 1 y 2 de la actualización de alcance
 * de SOUND POS) se revierten y se vuelven a aplicar sin alterar los datos, y se
 * detienen SIN CAMBIOS si el esquema o los datos son incoherentes.
 *
 * Igual que MigracionCajasTest: no puede ir dentro de la transacción de
 * RefreshDatabase (en MySQL el DDL confirma implícitamente). Cada prueba parte
 * de un migrate:fresh propio y obliga a la siguiente clase a migrar de cero.
 * Solo se revierten migraciones de esta etapa.
 */
class MigracionReservasTest extends TestCase
{
    /** 8 del paso 1 + 2 de la actualización de alcance */
    private const PASOS_ETAPA_4 = 10;
    private const PASOS_ALCANCE = 2;

    /** Tablas que deja la Etapa 4 (reserva_garantias se crea y después se elimina) */
    private const TABLAS_ETAPA_4 = [
        'reserva_eventos', 'reserva_entregas', 'reserva_entrega_detalles', 'reserva_incidencias',
        'reserva_cargos', 'maquinaria_bloqueos',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true]);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    // ───────────────────────── Ayudantes ─────────────────────────

    private function revertirEtapa4(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::PASOS_ETAPA_4]);

        foreach ([...self::TABLAS_ETAPA_4, 'reserva_garantias'] as $t) {
            $this->assertFalse(Schema::hasTable($t), "La reversión debía quitar {$t}");
        }
        $this->assertFalse(Schema::hasColumn('pagos', 'concepto'));
        $this->assertFalse(Schema::hasColumn('reservas', 'canal'));
        // Las tablas de etapas anteriores siguen ahí
        $this->assertTrue(Schema::hasTable('caja_eventos'));
        $this->assertTrue(Schema::hasTable('reserva_equipos'));
        $this->assertIndiceSimpleDeReservaEnPagos();
    }

    private function checksMysql()
    {
        return collect(DB::select('SELECT constraint_name AS n FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()'))
            ->pluck('n')->map(fn ($n) => strtolower($n));
    }

    /** MySQL: la FK pagos.reserva_id siempre debe tener un índice simple utilizable. */
    private function assertIndiceSimpleDeReservaEnPagos(): void
    {
        $indices = collect(Schema::getIndexes('pagos'));
        $this->assertFalse($indices->pluck('name')->contains('pagos_reserva_concepto_index'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue($indices->contains(fn ($i) => $i['columns'] === ['reserva_id']), 'Falta el índice simple de pagos.reserva_id');
        }
    }

    /** Estado final de la Etapa 4 (alcance de SOUND POS). */
    private function assertEsquemaFinal(): void
    {
        foreach (self::TABLAS_ETAPA_4 as $t) {
            $this->assertTrue(Schema::hasTable($t), "Falta {$t}");
        }
        $this->assertFalse(Schema::hasTable('reserva_garantias'));
        $this->assertFalse(Schema::hasColumn('pagos', 'concepto'));
        $this->assertFalse(Schema::hasColumn('reserva_cargos', 'horas'));
        $this->assertTrue(Schema::hasColumn('reservas', 'total_cargos'));
        $this->assertIndiceSimpleDeReservaEnPagos();
        if (DB::getDriverName() === 'mysql') {
            $checks = $this->checksMysql();
            foreach (['reservas_total_cargos_check', 'reserva_cargos_monto_check', 'maquinaria_bloqueos_intervalo_check'] as $c) {
                $this->assertTrue($checks->contains($c), "Falta la restricción {$c}");
            }
            foreach (['pagos_concepto_garantia_check', 'reserva_cargos_horas_check', 'reserva_garantias_monto_check'] as $c) {
                $this->assertFalse($checks->contains($c), "Sobra la restricción {$c}");
            }
        }
    }

    private function reservaCruda(int $ci, array $extra = []): int
    {
        return DB::table('reservas')->insertGetId($extra + [
            'codigo' => 'R-' . Str::upper(Str::random(8)),
            'ci_cliente' => $ci,
            'estado' => 'confirmada',
            'estado_pago' => 'parcial',
            'fecha_inicio' => '2026-12-01 10:00:00',
            'fecha_fin' => '2026-12-01 14:00:00',
            'horas' => 4,
            'total' => '400.00',
            'created_at' => '2026-11-20 09:00:00',
            'updated_at' => '2026-11-20 09:00:00',
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
            'estado_aplicacion' => 'aplicado',
            'monto' => '123.45',
            'snapshot' => json_encode(['tipo' => 'reserva']),
            'created_at' => '2026-11-20 09:30:00',
            'updated_at' => '2026-11-20 09:30:00',
        ]);
    }

    private function fila(string $tabla, int $id): array
    {
        return (array) DB::table($tabla)->where('id', $id)->first();
    }

    // ───────────────────────── Etapa 4 completa ─────────────────────────

    public function test_el_estado_final_no_tiene_garantias_ni_horas_extra(): void
    {
        $this->assertEsquemaFinal();
    }

    public function test_datos_existentes_se_conservan_en_toda_la_etapa(): void
    {
        $this->revertirEtapa4();

        $cliente = UsuarioFactory::new()->cliente()->create();
        $reserva = $this->reservaCruda($cliente->ci);
        $pagoReserva = $this->pagoCrudo(['reserva_id' => $reserva, 'ci_usuario' => $cliente->ci]);
        $pagoVenta = $this->pagoCrudo(['monto' => '0.10', 'snapshot' => json_encode(['tipo' => 'venta_presencial'])]);

        $antes = [
            'reserva' => $this->fila('reservas', $reserva),
            'pago1' => $this->fila('pagos', $pagoReserva),
            'pago2' => $this->fila('pagos', $pagoVenta),
        ];

        Artisan::call('migrate');

        $despues = [
            'reserva' => $this->fila('reservas', $reserva),
            'pago1' => $this->fila('pagos', $pagoReserva),
            'pago2' => $this->fila('pagos', $pagoVenta),
        ];
        foreach ($antes as $clave => $fila) {
            foreach ($fila as $columna => $valor) {
                $this->assertEquals($valor, $despues[$clave][$columna], "{$clave}.{$columna} cambió al migrar");
            }
        }
        // Los pagos quedan con exactamente las mismas columnas (concepto se creó y se quitó)
        $this->assertSame(array_keys($antes['pago1']), array_keys($despues['pago1']));

        // Columnas nuevas de reservas: valores neutros, sin inventar datos
        $this->assertNull($despues['reserva']['canal']);
        $this->assertEquals(0, $despues['reserva']['total_cargos']);
        $this->assertNull($despues['reserva']['clave_idempotencia']);
        foreach (self::TABLAS_ETAPA_4 as $t) {
            $this->assertSame(0, DB::table($t)->count(), "{$t} debía quedar vacía");
        }

        // Ciclo completo otra vez
        $this->revertirEtapa4();
        $this->assertEquals($antes['pago1']['monto'], $this->fila('pagos', $pagoReserva)['monto']);
        Artisan::call('migrate');
        $this->assertEsquemaFinal();
    }

    public function test_se_detiene_sin_cambios_si_el_esquema_ya_tiene_las_columnas(): void
    {
        $this->revertirEtapa4();
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('canal', 10)->nullable();
        });

        try {
            Artisan::call('migrate');
            $this->fail('La migración debía detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No se modificó nada', $e->getMessage());
            $this->assertStringContainsString('canal', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('reservas', 'total_cargos'));
        $this->assertFalse(Schema::hasColumn('pagos', 'concepto'));
        foreach (self::TABLAS_ETAPA_4 as $t) {
            $this->assertFalse(Schema::hasTable($t));
        }
    }

    public function test_se_detiene_sin_cambios_si_hay_reservas_invalidas(): void
    {
        $this->revertirEtapa4();
        $cliente = UsuarioFactory::new()->cliente()->create();

        if (DB::getDriverName() === 'mysql') {
            // En MySQL la propia base (CHECK de la Etapa 1) impide guardarla
            $this->assertThrows(fn () => $this->reservaCruda($cliente->ci, ['fecha_fin' => '2026-12-01 09:00:00']), QueryException::class);
            return;
        }

        $this->reservaCruda($cliente->ci, ['fecha_fin' => '2026-12-01 09:00:00']);

        try {
            Artisan::call('migrate');
            $this->fail('La migración debía detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No se modificó nada', $e->getMessage());
            $this->assertStringContainsString('1 reserva(s)', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('reservas', 'canal'));
        $this->assertFalse(Schema::hasTable('reserva_eventos'));
    }

    // ───────────────────────── Actualización de alcance ─────────────────────────

    public function test_la_actualizacion_de_alcance_se_revierte_y_se_reaplica(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::PASOS_ALCANCE]);

        // Estado del paso 1, restaurado exactamente
        $this->assertTrue(Schema::hasTable('reserva_garantias'));
        $this->assertTrue(Schema::hasColumn('pagos', 'concepto'));
        $this->assertTrue(Schema::hasColumn('reserva_cargos', 'horas'));
        $this->assertTrue(collect(Schema::getIndexes('pagos'))->pluck('name')->contains('pagos_reserva_concepto_index'));
        if (DB::getDriverName() === 'mysql') {
            $checks = $this->checksMysql();
            foreach (['pagos_concepto_garantia_check', 'reserva_cargos_horas_check', 'reserva_garantias_monto_check', 'reserva_garantias_dinero_check'] as $c) {
                $this->assertTrue($checks->contains($c), "Falta la restricción {$c} tras revertir");
            }
        }

        Artisan::call('migrate');
        $this->assertEsquemaFinal();
    }

    public function test_la_actualizacion_se_detiene_si_hay_garantias_u_horas_extra(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::PASOS_ALCANCE]);

        $cliente = UsuarioFactory::new()->cliente()->create();
        $encargado = UsuarioFactory::new()->conRol('Encargado')->create();
        $reserva = $this->reservaCruda($cliente->ci);
        DB::table('reserva_cargos')->insert([
            'reserva_id' => $reserva, 'concepto' => 'horas_extra', 'horas' => 2, 'monto' => '100.00', 'moneda' => 'BOB',
            'estado' => 'vigente', 'ci_registrado_por' => $encargado->ci, 'clave_idempotencia' => 'he-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            Artisan::call('migrate');
            $this->fail('La migración debía detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No se modificó nada', $e->getMessage());
            $this->assertStringContainsString('1 cargo(s) por horas extra', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('reserva_garantias'));
        $this->assertTrue(Schema::hasColumn('reserva_cargos', 'horas'));
        $this->assertSame(1, DB::table('reserva_cargos')->count());

        // Sin el cargo, la de Rentals avanza; un pago de garantía detiene la de Finanzas
        DB::table('reserva_cargos')->delete();
        $pago = $this->pagoCrudo(['reserva_id' => $reserva, 'concepto' => 'garantia']);
        try {
            Artisan::call('migrate');
            $this->fail('La migración de pagos debía detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 pago(s) registrados como garantía', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('reserva_garantias'));
        $this->assertTrue(Schema::hasColumn('pagos', 'concepto'));
        $this->assertSame('garantia', $this->fila('pagos', $pago)['concepto']);
    }

    public function test_hay_52_migraciones_y_las_10_de_la_etapa_4_son_las_ultimas(): void
    {
        $archivos = collect(array_merge(
            glob(base_path('database/migrations/*.php')),
            glob(base_path('Modules/*/database/migrations/*.php'))
        ))->map(fn ($f) => basename($f, '.php'))->sort()->values();

        $this->assertCount(52, $archivos);
        $this->assertSame([
            '2026_11_25_000001_add_operacion_to_reservas_table',
            '2026_11_25_000002_create_reserva_eventos_table',
            '2026_11_25_000003_create_reserva_entregas_tables',
            '2026_11_25_000004_create_reserva_incidencias_table',
            '2026_11_25_000005_create_reserva_garantias_table',
            '2026_11_25_000006_create_reserva_cargos_table',
            '2026_11_25_000007_create_maquinaria_bloqueos_table',
            '2026_11_25_000008_add_concepto_to_pagos_table',
            '2026_11_26_000001_remove_garantias_y_horas_extra_de_reservas',
            '2026_11_26_000002_remove_concepto_from_pagos_table',
        ], $archivos->slice(-self::PASOS_ETAPA_4)->values()->all());

        $this->assertCount(52, DB::table('migrations')->pluck('migration'));
    }
}
