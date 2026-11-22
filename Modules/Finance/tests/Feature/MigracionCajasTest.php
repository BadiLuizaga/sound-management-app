<?php

namespace Modules\Finance\Tests\Feature;

use Database\Factories\UsuarioFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Etapa 3 — paso 1: migraciones de caja. Las cajas existentes no se recalculan
 * ni se modifican; la base impide dos cajas abiertas por usuario.
 *
 * Estas pruebas revierten y reaplican las 5 migraciones de la Etapa 3, así que
 * no pueden ir dentro de la transacción de RefreshDatabase (en MySQL el DDL
 * confirma implícitamente). Cada prueba parte de un migrate:fresh propio y, al
 * terminar, obliga a la siguiente clase a migrar desde cero. No se revierten
 * las migraciones de etapas anteriores.
 */
class MigracionCajasTest extends TestCase
{
    private const PASOS_ETAPA_3 = 5;

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

    private function cajaCruda(int $ci, string $estado, array $extra = []): int
    {
        return DB::table('cajas')->insertGetId($extra + [
            'user_id' => $ci,
            'monto_apertura' => '100.00',
            'estado' => $estado,
            'fecha_apertura' => '2026-11-01 08:00:00',
            'created_at' => '2026-11-01 08:00:00',
            'updated_at' => '2026-11-01 08:00:00',
        ]);
    }

    private function revertirEtapa3(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::PASOS_ETAPA_3]);
        $this->assertFalse(Schema::hasTable('movimientos_caja'), 'La reversión debía quitar las tablas de la Etapa 3');
    }

    public function test_columnas_y_tablas_nuevas(): void
    {
        $this->assertTrue(Schema::hasColumns('cajas', [
            'version_arqueo', 'efectivo_esperado', 'efectivo_contado', 'resumen', 'ci_cerrado_por',
            'cierre_supervisado', 'observaciones', 'clave_cierre', 'abierta_de',
        ]));
        $this->assertTrue(Schema::hasColumns('reembolsos', ['caja_id', 'medio_devolucion', 'ci_entregado_por', 'entregado_at']));
        $this->assertTrue(Schema::hasColumns('movimientos_caja', ['uuid', 'clave_idempotencia', 'caja_id', 'tipo', 'monto', 'motivo', 'revierte_id', 'caja_corregida_id', 'ci_registrado_por']));
        $this->assertTrue(Schema::hasColumns('caja_eventos', ['caja_id', 'tipo', 'ci_titular', 'ci_responsable', 'datos', 'ip']));
    }

    public function test_cajas_cerradas_existentes_se_marcan_heredadas_sin_cambiar_importes(): void
    {
        $this->revertirEtapa3();
        $u = UsuarioFactory::new()->conRol('Vendedor')->create();
        $cerrada = $this->cajaCruda($u->ci, 'cerrada', [
            'monto_cierre' => '432.10', 'diferencia' => '-17.90', 'fecha_cierre' => '2026-11-01 20:00:00',
        ]);
        $antes = (array) DB::table('cajas')->where('id', $cerrada)->first();

        Artisan::call('migrate');

        $despues = (array) DB::table('cajas')->where('id', $cerrada)->first();
        foreach ($antes as $campo => $valor) {
            $this->assertEquals($valor, $despues[$campo], "La columna {$campo} de la caja cerrada cambió");
        }
        $this->assertSame(1, (int) $despues['version_arqueo']);
        $this->assertNull($despues['efectivo_esperado']);
        $this->assertNull($despues['abierta_de']);
    }

    public function test_la_base_impide_dos_cajas_abiertas_del_mismo_usuario(): void
    {
        $u = UsuarioFactory::new()->conRol('Vendedor')->create();
        $this->cajaCruda($u->ci, 'abierta');
        $this->cajaCruda($u->ci, 'cerrada'); // las cerradas no cuentan
        $this->cajaCruda($u->ci, 'cerrada');

        $this->expectException(QueryException::class);
        $this->cajaCruda($u->ci, 'abierta');
    }

    public function test_al_cerrar_se_libera_el_usuario_para_otra_apertura(): void
    {
        $u = UsuarioFactory::new()->conRol('Vendedor')->create();
        $id = $this->cajaCruda($u->ci, 'abierta');
        DB::table('cajas')->where('id', $id)->update(['estado' => 'cerrada']);

        $this->cajaCruda($u->ci, 'abierta');
        $this->assertSame(1, DB::table('cajas')->where('abierta_de', $u->ci)->count());
    }

    public function test_se_detiene_sin_cambios_si_ya_hay_cajas_abiertas_duplicadas(): void
    {
        $this->revertirEtapa3();
        $u = UsuarioFactory::new()->conRol('Vendedor')->create();
        $this->cajaCruda($u->ci, 'abierta');
        $this->cajaCruda($u->ci, 'abierta');

        try {
            Artisan::call('migrate');
            $this->fail('La migración debía detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No se modificó nada', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('cajas', 'version_arqueo'));
        $this->assertSame(2, DB::table('cajas')->where('estado', 'abierta')->count());
    }

    public function test_reversion_y_nueva_migracion_conservan_los_datos(): void
    {
        $u = UsuarioFactory::new()->conRol('Vendedor')->create();
        $abierta = $this->cajaCruda($u->ci, 'abierta');

        $this->revertirEtapa3();
        $this->assertFalse(Schema::hasColumn('cajas', 'abierta_de'));
        $this->assertTrue(DB::table('cajas')->where('id', $abierta)->exists());

        Artisan::call('migrate');
        $this->assertTrue(Schema::hasTable('caja_eventos'));
        $this->assertSame((int) $u->ci, (int) DB::table('cajas')->where('id', $abierta)->value('abierta_de'));
    }

    public function test_restricciones_check_en_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->assertTrue(true); // SQLite: las CHECK solo se crean en MySQL (igual que en la Etapa 2)
            return;
        }
        $checks = collect(DB::select("SELECT constraint_name AS n FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()"))
            ->pluck('n')->map(fn ($n) => strtolower($n));
        $this->assertTrue($checks->contains('cajas_monto_apertura_check'));
        $this->assertTrue($checks->contains('movimientos_caja_monto_check'));
    }
}
