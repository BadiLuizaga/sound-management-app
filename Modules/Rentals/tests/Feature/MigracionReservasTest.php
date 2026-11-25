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
 * Etapa 4 — paso 1: las 8 migraciones nuevas se revierten y se vuelven a
 * aplicar sin alterar los datos existentes, y se detienen SIN CAMBIOS si el
 * esquema o los datos son incoherentes.
 *
 * Igual que MigracionCajasTest: no puede ir dentro de la transacción de
 * RefreshDatabase (en MySQL el DDL confirma implícitamente). Cada prueba parte
 * de un migrate:fresh propio y obliga a la siguiente clase a migrar de cero.
 * Solo se revierten las 8 migraciones de esta etapa.
 */
class MigracionReservasTest extends TestCase
{
    private const PASOS_ETAPA_4 = 8;

    private const TABLAS_NUEVAS = [
        'reserva_eventos', 'reserva_entregas', 'reserva_entrega_detalles', 'reserva_incidencias',
        'reserva_garantias', 'reserva_cargos', 'maquinaria_bloqueos',
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

    private function revertirEtapa4(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::PASOS_ETAPA_4]);

        foreach (self::TABLAS_NUEVAS as $t) {
            $this->assertFalse(Schema::hasTable($t), "La reversión debía quitar {$t}");
        }
        $this->assertFalse(Schema::hasColumn('pagos', 'concepto'));
        $this->assertFalse(Schema::hasColumn('reservas', 'canal'));
        // Las tablas de etapas anteriores siguen ahí
        $this->assertTrue(Schema::hasTable('caja_eventos'));
        $this->assertTrue(Schema::hasTable('reserva_equipos'));

        $indicesPagos = collect(Schema::getIndexes('pagos'));
        $this->assertFalse($indicesPagos->pluck('name')->contains('pagos_reserva_concepto_index'));
        if (DB::getDriverName() === 'mysql') {
            // La FK pagos.reserva_id vuelve a tener su índice simple, como antes de la Etapa 4
            $this->assertTrue($indicesPagos->contains(fn ($i) => $i['columns'] === ['reserva_id']), 'Falta el índice simple de pagos.reserva_id');
            $this->assertFalse($this->checksMysql()->contains('pagos_concepto_garantia_check'));
        }
    }

    private function checksMysql()
    {
        return collect(DB::select('SELECT constraint_name AS n FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()'))
            ->pluck('n')->map(fn ($n) => strtolower($n));
    }

    private function assertEsquemaEtapa4Completo(): void
    {
        foreach (self::TABLAS_NUEVAS as $t) {
            $this->assertTrue(Schema::hasTable($t), "Falta {$t}");
        }
        $this->assertTrue(Schema::hasColumn('pagos', 'concepto'));
        $this->assertTrue(collect(Schema::getIndexes('pagos'))->pluck('name')->contains('pagos_reserva_concepto_index'));
        if (DB::getDriverName() === 'mysql') {
            $checks = $this->checksMysql();
            foreach (['pagos_concepto_garantia_check', 'reservas_total_cargos_check', 'reserva_garantias_monto_check', 'maquinaria_bloqueos_intervalo_check'] as $c) {
                $this->assertTrue($checks->contains($c), "Falta la restricción {$c}");
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

    public function test_datos_existentes_se_conservan_y_los_pagos_quedan_como_alquiler(): void
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

        // Columnas nuevas: valores neutros, sin inventar datos
        $this->assertSame('alquiler', $despues['pago1']['concepto']);
        $this->assertSame('alquiler', $despues['pago2']['concepto']);
        $this->assertNull($despues['reserva']['canal']);
        $this->assertEquals(0, $despues['reserva']['total_cargos']);
        $this->assertNull($despues['reserva']['clave_idempotencia']);
        foreach (self::TABLAS_NUEVAS as $t) {
            $this->assertTrue(Schema::hasTable($t));
            $this->assertSame(0, DB::table($t)->count(), "{$t} debía quedar vacía");
        }

        // Ciclo completo otra vez: revertir y volver a aplicar conserva los datos
        $this->revertirEtapa4();
        $this->assertEquals($antes['pago1']['monto'], $this->fila('pagos', $pagoReserva)['monto']);
        Artisan::call('migrate');
        $this->assertSame('alquiler', $this->fila('pagos', $pagoReserva)['concepto']);
        $this->assertEsquemaEtapa4Completo();
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

        // Nada de la Etapa 4 se aplicó
        $this->assertFalse(Schema::hasColumn('reservas', 'total_cargos'));
        $this->assertFalse(Schema::hasColumn('pagos', 'concepto'));
        foreach (self::TABLAS_NUEVAS as $t) {
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

        // SQLite no tiene esas CHECK: la migración lo detecta antes de cambiar nada
        $this->reservaCruda($cliente->ci, ['fecha_fin' => '2026-12-01 09:00:00']);

        try {
            Artisan::call('migrate');
            $this->fail('La migración debía detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No se modificó nada', $e->getMessage());
            $this->assertStringContainsString('1 reserva(s)', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('reservas', 'canal'));
        $this->assertFalse(Schema::hasColumn('pagos', 'concepto'));
        $this->assertFalse(Schema::hasTable('reserva_eventos'));
    }

    public function test_hay_50_migraciones_y_las_8_nuevas_son_las_ultimas(): void
    {
        $archivos = collect(array_merge(
            glob(base_path('database/migrations/*.php')),
            glob(base_path('Modules/*/database/migrations/*.php'))
        ))->map(fn ($f) => basename($f, '.php'))->sort()->values();

        $this->assertCount(50, $archivos);
        $this->assertSame([
            '2026_11_25_000001_add_operacion_to_reservas_table',
            '2026_11_25_000002_create_reserva_eventos_table',
            '2026_11_25_000003_create_reserva_entregas_tables',
            '2026_11_25_000004_create_reserva_incidencias_table',
            '2026_11_25_000005_create_reserva_garantias_table',
            '2026_11_25_000006_create_reserva_cargos_table',
            '2026_11_25_000007_create_maquinaria_bloqueos_table',
            '2026_11_25_000008_add_concepto_to_pagos_table',
        ], $archivos->slice(-self::PASOS_ETAPA_4)->values()->all());

        $ejecutadas = DB::table('migrations')->pluck('migration');
        $this->assertCount(50, $ejecutadas);
    }
}
