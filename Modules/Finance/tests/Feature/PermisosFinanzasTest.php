<?php

namespace Modules\Finance\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\Rol;
use Modules\Audit\Services\ReadOnlySqlGuard;
use Modules\Finance\Models\Pago;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 2 — paso 7: permisos financieros por rol y coherencia botones ↔ rutas.
 */
class PermisosFinanzasTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        PayPalFalso::instalar();
    }

    /** Pago de caja (transferencia pendiente) registrado por $cajero, sin pasar por HTTP. */
    private function pagoDe($cajero, string $estado = 'pendiente_verificacion', string $metodo = MetodoPago::TRANSFERENCIA): Pago
    {
        $caja = $this->abrirCaja($cajero);

        return Pago::create([
            'uuid' => (string) Str::uuid(),
            'canal' => 'presencial',
            'proveedor' => 'manual',
            'metodo_pago_id' => MetodoPago::idPorCodigo($metodo),
            'estado' => $estado,
            'estado_aplicacion' => 'pendiente',
            'monto' => '80.00',
            'moneda' => 'BOB',
            'referencia_externa' => 'REF-' . Str::random(8),
            'ci_registrado_por' => $cajero->ci,
            'caja_id' => $caja->id,
            'snapshot' => ['tipo' => 'venta_presencial', 'items' => []],
        ]);
    }

    public function test_acceso_al_listado_de_pagos_por_rol(): void
    {
        $esperado = ['Administrador' => 200, 'Encargado' => 200, 'Vendedor' => 200, 'Técnico' => 403, 'Almacenero' => 403];
        foreach ($esperado as $rol => $codigo) {
            $this->actingAs($this->usuarioConRol($rol))->get('/finanzas/pagos')->assertStatus($codigo);
        }
        $this->actingAs($this->cliente())->get('/finanzas/pagos')->assertForbidden();
    }

    public function test_el_vendedor_solo_ve_sus_propios_pagos_de_caja(): void
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $otro = $this->usuarioConRol('Vendedor');
        $mio = $this->pagoDe($vendedor);
        $ajeno = $this->pagoDe($otro);

        $this->actingAs($vendedor)->get('/finanzas/pagos')->assertOk()
            ->assertSee(route('finanzas.admin.pagos.show', $mio), false)
            ->assertDontSee(route('finanzas.admin.pagos.show', $ajeno), false);

        $this->actingAs($vendedor)->get(route('finanzas.admin.pagos.show', $mio))->assertOk();
        $this->actingAs($vendedor)->get(route('finanzas.admin.pagos.show', $ajeno))->assertForbidden();
    }

    public function test_verificar_transferencia_solo_admin_y_encargado(): void
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $pago = $this->pagoDe($vendedor);

        $this->actingAs($vendedor)->post(route('finanzas.admin.pagos.verificar', $pago))->assertForbidden();
        $this->actingAs($this->usuarioConRol('Técnico'))->post(route('finanzas.admin.pagos.verificar', $pago))->assertForbidden();

        $this->assertSame('pendiente_verificacion', $pago->fresh()->estado->value);
    }

    public function test_tipos_de_cambio_solo_administrador(): void
    {
        $this->actingAs($this->admin)->get('/finanzas/tipos-cambio')->assertOk();
        $this->actingAs($this->usuarioConRol('Encargado'))->get('/finanzas/tipos-cambio')->assertForbidden();
        $this->actingAs($this->usuarioConRol('Encargado'))->post('/finanzas/tipos-cambio', ['tasa' => '6.96', 'fecha_aplicacion' => '2026-11-25'])->assertForbidden();

        $this->actingAs($this->admin)->post('/finanzas/tipos-cambio', ['tasa' => '6.96', 'fecha_aplicacion' => '2026-11-25'])->assertSessionHas('success');
        $this->assertTrue(\Modules\Finance\Models\TipoCambio::whereDate('fecha_aplicacion', '2026-11-25')->exists());
    }

    public function test_botones_coherentes_con_los_permisos(): void
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $encargado = $this->usuarioConRol('Encargado');
        $pendiente = $this->pagoDe($vendedor);

        // El vendedor ve su pago pendiente pero NO el botón de verificar
        $this->actingAs($vendedor)->get(route('finanzas.admin.pagos.show', $pendiente))->assertOk()
            ->assertDontSee(route('finanzas.admin.pagos.verificar', $pendiente), false);

        // El encargado sí
        $this->actingAs($encargado)->get(route('finanzas.admin.pagos.show', $pendiente))->assertOk()
            ->assertSee(route('finanzas.admin.pagos.verificar', $pendiente), false);

        // SOUND POS no gestiona reembolsos: nadie ve acciones de reembolso
        $recibido = $this->pagoDe($vendedor, 'recibido', MetodoPago::TARJETA);
        foreach ([$encargado, $this->admin] as $usuario) {
            $this->actingAs($usuario)->get(route('finanzas.admin.pagos.show', $recibido))->assertOk()
                ->assertDontSee('Solicitar reembolso')
                ->assertDontSee('/reembolsos', false);
        }
    }

    public function test_menu_lateral_segun_permisos(): void
    {
        $this->actingAs($this->usuarioConRol('Vendedor'))->get('/finanzas/pagos')
            ->assertSee(route('finanzas.admin.pagos.index'), false)
            ->assertDontSee(route('finanzas.admin.tipos-cambio.index'), false);

        $this->actingAs($this->admin)->get('/finanzas/pagos')
            ->assertSee(route('finanzas.admin.tipos-cambio.index'), false);
    }

    public function test_un_rol_personalizado_no_recibe_permisos_financieros_por_defecto(): void
    {
        Rol::create(['id' => 50, 'nombre' => 'Cajero Nocturno']);
        $custom = $this->usuarioConRol('Cajero Nocturno');

        $this->actingAs($custom)->get('/finanzas/pagos')->assertForbidden();
    }

    public function test_el_sembrado_no_quita_asignaciones_personalizadas(): void
    {
        Rol::create(['id' => 60, 'nombre' => 'Auditor Externo']);
        $permiso = DB::table('permisos')->where('codigo', 'finance.audit.view')->value('id');
        DB::table('rol_permiso')->insert(['id_rol' => 60, 'permiso_id' => $permiso, 'created_at' => now(), 'updated_at' => now()]);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(DB::table('rol_permiso')->where('id_rol', 60)->where('permiso_id', $permiso)->exists());
        $this->assertFalse(DB::table('rol_permiso')->join('rol', 'rol.id', '=', 'rol_permiso.id_rol')
            ->join('permisos', 'permisos.id', '=', 'rol_permiso.permiso_id')
            ->whereIn('rol.nombre', ['Técnico', 'Almacenero'])->where('permisos.codigo', 'like', 'finance.%')->exists());
    }

    public function test_los_reportes_con_ia_no_pueden_leer_tablas_financieras(): void
    {
        $guard = new ReadOnlySqlGuard();
        foreach (['SELECT monto FROM pagos', 'SELECT tipo FROM pago_eventos', 'SELECT monto FROM reembolsos', 'SELECT tipo FROM webhook_eventos'] as $sql) {
            try {
                $guard->validate($sql);
                $this->fail("Se permitió: {$sql}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
