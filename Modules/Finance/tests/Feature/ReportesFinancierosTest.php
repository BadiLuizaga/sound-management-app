<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Services\ReadOnlySqlGuard;
use Modules\Finance\Http\Controllers\Admin\ReporteFinancieroController;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Services\CajaService;
use Modules\Finance\Services\ReporteFinancieroService;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 3 — paso 7: reportes financieros y conciliación (pantalla y CSV, D8).
 */
class ReportesFinancierosTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private $vendedor;
    private $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        PayPalFalso::instalar();
        $this->vendedor = $this->usuarioConRol('Vendedor');
        $this->encargado = $this->usuarioConRol('Encargado');
    }

    private function pago(string $metodo, string $monto, string $estado, ?string $recibido, array $extra = []): Pago
    {
        return Pago::create($extra + [
            'uuid' => (string) Str::uuid(),
            'canal' => 'presencial',
            'proveedor' => $metodo === MetodoPago::PAYPAL ? 'paypal' : 'manual',
            'metodo_pago_id' => MetodoPago::idPorCodigo($metodo),
            'estado' => $estado,
            'estado_aplicacion' => 'aplicado',
            'monto' => $monto,
            'moneda' => 'BOB',
            'ci_registrado_por' => $this->vendedor->ci,
            'snapshot' => ['tipo' => 'venta_presencial', 'items' => []],
            'recibido_at' => $recibido,
        ]);
    }

    private function generar(string $reporte, string $desde = '2026-11-01', string $hasta = '2026-11-30'): array
    {
        return app(ReporteFinancieroService::class)->generar($reporte, \Carbon\Carbon::parse($desde), \Carbon\Carbon::parse($hasta));
    }

    public function test_acceso_solo_administrador_y_encargado(): void
    {
        foreach ([$this->admin, $this->encargado] as $u) {
            $this->actingAs($u)->get(route('finanzas.admin.reportes.index'))->assertOk();
            $this->actingAs($u)->get(route('finanzas.admin.reportes.csv'))->assertOk();
        }
        foreach (['Vendedor', 'Técnico', 'Almacenero'] as $rol) {
            $u = $this->usuarioConRol($rol);
            $this->actingAs($u)->get(route('finanzas.admin.reportes.index'))->assertForbidden();
            $this->actingAs($u)->get(route('finanzas.admin.reportes.csv'))->assertForbidden();
        }
    }

    public function test_cobros_por_dia_y_metodo_con_total(): void
    {
        $this->pago(MetodoPago::EFECTIVO, '10.10', 'recibido', '2026-11-05 10:00:00');
        $this->pago(MetodoPago::EFECTIVO, '0.20', 'reembolsado_parcial', '2026-11-05 18:00:00');
        $this->pago(MetodoPago::TARJETA, '50.00', 'recibido', '2026-11-06 09:00:00', ['referencia_externa' => 'AUT-1']);
        $this->pago(MetodoPago::QR, '99.00', 'pendiente_verificacion', null, ['referencia_externa' => 'QR-1']);   // no recibido
        $this->pago(MetodoPago::TRANSFERENCIA, '77.00', 'rechazado', null, ['referencia_externa' => 'TR-1']);    // no recibido
        $this->pago(MetodoPago::EFECTIVO, '5.00', 'recibido', '2026-10-31 23:59:00');                          // fuera del período

        $t = $this->generar('cobros_por_dia');

        $this->assertSame(['Fecha', 'Método', 'Operaciones', 'Importe (BOB)'], $t['columnas']);
        $efectivo = MetodoPago::where('codigo', 'EFECTIVO')->value('nombre');
        $tarjeta = MetodoPago::where('codigo', 'TARJETA')->value('nombre');
        $this->assertSame([
            ['2026-11-05', $efectivo, 2, '10.30'],
            ['2026-11-06', $tarjeta, 1, '50.00'],
            ['TOTAL', '', 3, '60.30'],
        ], $t['filas']);
    }

    public function test_cobros_por_usuario_distingue_los_pagos_en_linea(): void
    {
        $this->pago(MetodoPago::EFECTIVO, '10.00', 'recibido', '2026-11-05 10:00:00');
        $this->pago(MetodoPago::PAYPAL, '40.00', 'recibido', '2026-11-05 11:00:00', ['canal' => 'en_linea', 'ci_registrado_por' => null]);

        $usuarios = array_column($this->generar('cobros_por_usuario')['filas'], 0);

        $this->assertContains($this->vendedor->nombre, $usuarios);
        $this->assertContains('En línea (cliente)', $usuarios);
    }

    public function test_reporte_de_diferencias(): void
    {
        $cajas = app(CajaService::class);
        $conDiferencia = $cajas->abrir($this->vendedor, Dinero::deDecimal('100', 'BOB'));
        $cajas->cerrar($conDiferencia, $this->vendedor, Dinero::deDecimal('95', 'BOB'), 'Faltante', 'cierre-rep-00000001');
        $exacta = $cajas->abrir($this->encargado, Dinero::deDecimal('10', 'BOB'));
        $cajas->cerrar($exacta, $this->encargado, Dinero::deDecimal('10', 'BOB'), null, 'cierre-rep-00000002');

        $hoy = now()->toDateString();
        $filas = $this->generar('diferencias', $hoy, $hoy)['filas'];

        $this->assertCount(1, $filas);
        $this->assertSame($conDiferencia->id, $filas[0][0]);
        $this->assertSame('-5.00', $filas[0][5]);
        $this->assertSame('Faltante', $filas[0][9]);
    }

    public function test_conciliacion_de_pagos_detecta_cada_hallazgo(): void
    {
        $this->pago(MetodoPago::EFECTIVO, '10.00', 'recibido', now()->toDateTimeString(), ['estado_aplicacion' => 'requiere_revision', 'motivo_revision' => 'Sin stock']);
        $this->pago(MetodoPago::QR, '20.00', 'pendiente_verificacion', null, ['referencia_externa' => 'QR-PEND-1']);
        $verificando = $this->pago(MetodoPago::PAYPAL, '30.00', 'verificando', null, ['canal' => 'en_linea', 'referencia_externa' => 'ORDER-1']);
        DB::table('pagos')->where('id', $verificando->id)->update(['updated_at' => now()->subHours(30)]);

        // Venta presencial sin pago ligado (como las anteriores a la Etapa 2)
        $this->abrirCaja($this->vendedor);
        $this->producto(10, '50.00', 5);
        $this->actingAs($this->vendedor)->postJson('/ventas', [
            'ci_cliente' => $this->cliente()->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'productos' => [['id' => 10, 'cantidad' => 1]],
            'efectivo_recibido' => '50',
            'clave_idempotencia' => 'pos-conciliacion-0001',
        ])->assertOk();
        $base = DB::table('NotaVenta')->first();
        DB::table('NotaVenta')->insert([
            'nro' => $base->nro + 1, 'fecha' => now(), 'total' => '12.00',
            'ci_cliente' => $base->ci_cliente, 'ci_empleado' => $base->ci_empleado, 'id_pago' => $base->id_pago,
        ]);

        $hoy = now()->toDateString();
        $hallazgos = array_column($this->generar('conciliacion_pagos', $hoy, $hoy)['filas'], 0);

        $this->assertContains('Recibido sin venta (requiere revisión)', $hallazgos);
        $this->assertContains('Pendiente de verificación', $hallazgos);
        $this->assertContains('Verificando más de 24 h', $hallazgos);
        $this->assertContains('Venta presencial sin pago ligado', $hallazgos);
        $this->assertSame(1, count(array_keys($hallazgos, 'Venta presencial sin pago ligado')), 'la venta con pago no aparece');
    }

    public function test_conciliacion_de_reembolsos(): void
    {
        $pago = $this->pago(MetodoPago::EFECTIVO, '80.00', 'recibido', now()->toDateTimeString());
        foreach ([['autorizado', null], ['completado', null], ['completado', 'efectivo']] as $i => [$estado, $medio]) {
            Reembolso::create([
                'uuid' => (string) Str::uuid(), 'clave_idempotencia' => 'rep-reemb-' . $i, 'pago_id' => $pago->id,
                'estado' => $estado, 'origen' => 'aplicacion', 'monto' => '5.00', 'moneda' => 'BOB', 'motivo' => 'x',
                'medio_devolucion' => $medio, 'completado_at' => $estado === 'completado' ? now() : null,
            ]);
        }

        $hallazgos = array_column($this->generar('conciliacion_reembolsos')['filas'], 0);

        $this->assertSame(['Autorizado, falta entregar el dinero', 'Entregado sin caja ni medio registrados (anterior a la Etapa 3)'], $hallazgos);
    }

    public function test_csv_con_columnas_fijas_bom_y_sin_inyeccion_de_formulas(): void
    {
        $this->abrirCaja($this->encargado, '100.00');
        app(CajaService::class)->registrarMovimiento($this->encargado, 'egreso', Dinero::deDecimal('5.5', 'BOB'), '=HYPERLINK("http://x")', 'mov-csv-00000000001');

        $hoy = now()->toDateString();
        $respuesta = $this->actingAs($this->admin)->get(route('finanzas.admin.reportes.csv', ['reporte' => 'movimientos', 'desde' => $hoy, 'hasta' => $hoy]));
        $respuesta->assertOk();
        $this->assertStringContainsString('text/csv', $respuesta->headers->get('content-type'));

        $csv = $respuesta->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lineas = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame('Fecha,Caja,Tipo,"Importe (BOB)",Motivo,"Registrado por","Revierte al movimiento","Corrige la caja"', $lineas[0]);
        $this->assertStringContainsString(',egreso,5.50,', $lineas[1]);
        $this->assertStringContainsString("'=HYPERLINK", $lineas[1]);

        $this->assertSame('-5.00', ReporteFinancieroController::celdaSegura('-5.00'), 'los negativos siguen siendo números');
        $this->assertSame("'-x", ReporteFinancieroController::celdaSegura('-x'));
    }

    public function test_validacion_de_parametros(): void
    {
        $this->actingAs($this->admin)->get(route('finanzas.admin.reportes.index', ['reporte' => 'inventado']))->assertSessionHasErrors('reporte');
        $this->actingAs($this->admin)->get(route('finanzas.admin.reportes.index', ['desde' => '2026-11-10', 'hasta' => '2026-11-01']))->assertSessionHasErrors('hasta');
        $this->actingAs($this->admin)->get(route('finanzas.admin.reportes.index', ['desde' => '2024-01-01', 'hasta' => '2026-11-01']))->assertStatus(422);
    }

    public function test_menu_y_reportes_ia(): void
    {
        $this->actingAs($this->encargado)->get(route('caja.index'))->assertSee(route('finanzas.admin.reportes.index'), false);
        $this->actingAs($this->vendedor)->get(route('caja.index'))->assertDontSee(route('finanzas.admin.reportes.index'), false);

        $guard = new ReadOnlySqlGuard();
        foreach (['SELECT monto FROM movimientos_caja', 'SELECT tipo FROM caja_eventos'] as $sql) {
            try {
                $guard->validate($sql);
                $this->fail("Se permitió: {$sql}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
