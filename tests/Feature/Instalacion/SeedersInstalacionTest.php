<?php

namespace Tests\Feature\Instalacion;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Fase 2 — Etapa 1: datos iniciales mínimos, idempotentes y sin datos comerciales.
 */
class SeedersInstalacionTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS_DE_NEGOCIO = [
        'usuario', 'cliente', 'empleado', 'estadoRol', 'users',
        'producto', 'marca', 'categoria', 'color', 'medida', 'volumen',
        'proveedor', 'NotaVenta', 'detalleNotaVenta', 'NotaCompra', 'detalleNotaCompra',
        'maquinarias', 'alquileres', 'alquiler_detalles', 'mantenimientos',
        'devoluciones', 'pedidos_reabastecimiento', 'promociones', 'cotizaciones',
        'carritos', 'cajas', 'pagos_externos', 'bitacoras', 'bitacora',
        'paquetes', 'paquete_items', 'reservas', 'reserva_paquetes', 'reserva_equipos',
    ];

    public function test_metodos_de_pago_por_codigo(): void
    {
        $this->seed(DatabaseSeeder::class);

        $codigos = MetodoPago::orderBy('id')->pluck('codigo')->all();
        $this->assertSame(['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR', 'PAYPAL'], $codigos);
        $this->assertSame(5, MetodoPago::activos()->count());
        $this->assertSame('Transferencia bancaria', MetodoPago::where('codigo', 'TRANSFERENCIA')->value('nombre'));
    }

    public function test_roles_del_sistema(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = DB::table('rol')->orderBy('id')->pluck('nombre')->all();
        $this->assertSame(['Administrador', 'Encargado', 'Técnico', 'Almacenero', 'Vendedor'], $roles);
        $this->assertFalse(DB::table('rol')->where('nombre', 'Cliente')->exists(), 'El cliente no es un rol de personal');
    }

    public function test_permisos_sembrados_coinciden_con_la_matriz_de_acceso(): void
    {
        $this->seed(DatabaseSeeder::class);

        $esperados = collect(array_keys(config('access.abilities')))->reject(fn ($c) => $c === 'cliente')->sort()->values()->all();
        $this->assertSame($esperados, DB::table('permisos')->orderBy('codigo')->pluck('codigo')->all());

        $permisosDe = fn (string $rol) => DB::table('rol_permiso')
            ->join('rol', 'rol.id', '=', 'rol_permiso.id_rol')
            ->join('permisos', 'permisos.id', '=', 'rol_permiso.permiso_id')
            ->where('rol.nombre', $rol)->orderBy('permisos.codigo')->pluck('permisos.codigo')->all();

        $this->assertContains('admin', $permisosDe('Administrador'));
        $this->assertContains('reports.ai', $permisosDe('Administrador'));
        $this->assertNotContains('admin', $permisosDe('Vendedor'));
        $this->assertContains('maintenance.manage', $permisosDe('Técnico'));
        $this->assertNotContains('inventory.manage', $permisosDe('Técnico'));
    }

    public function test_seeders_son_idempotentes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $antes = [
            DB::table('metodoPago')->orderBy('id')->get(['id', 'codigo'])->toArray(),
            DB::table('rol')->orderBy('id')->get(['id', 'nombre'])->toArray(),
            DB::table('permisos')->count(),
            DB::table('rol_permiso')->count(),
        ];

        $this->seed(DatabaseSeeder::class);
        $despues = [
            DB::table('metodoPago')->orderBy('id')->get(['id', 'codigo'])->toArray(),
            DB::table('rol')->orderBy('id')->get(['id', 'nombre'])->toArray(),
            DB::table('permisos')->count(),
            DB::table('rol_permiso')->count(),
        ];

        $this->assertEquals($antes, $despues);
    }

    public function test_metodo_desactivado_no_se_reactiva_al_volver_a_sembrar(): void
    {
        $this->seed(DatabaseSeeder::class);
        MetodoPago::where('codigo', 'QR')->update(['activo' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse((bool) MetodoPago::where('codigo', 'QR')->value('activo'));
    }

    public function test_no_se_insertan_datos_comerciales_ni_usuarios(): void
    {
        $this->seed(DatabaseSeeder::class);

        $conDatos = [];
        foreach (self::TABLAS_DE_NEGOCIO as $tabla) {
            $n = DB::table($tabla)->count();
            if ($n > 0) {
                $conDatos[] = "{$tabla} ({$n})";
            }
        }

        $this->assertSame([], $conDatos, 'Tablas de negocio con datos tras sembrar: ' . implode(', ', $conDatos));
        $this->assertFalse(DB::table('usuario')->where('email', 'admin@ferre.bo')->exists());
    }

    public function test_seeders_heredados_eliminados(): void
    {
        $this->assertFalse(class_exists(\Database\Seeders\AdminUserSeeder::class));
        $this->assertFalse(class_exists(\Database\Seeders\MaquinariaSeeder::class));
    }
}
