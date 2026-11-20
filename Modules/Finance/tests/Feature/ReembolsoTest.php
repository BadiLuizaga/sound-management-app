<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Services\ConciliacionService;
use Modules\Finance\Services\ReembolsoService;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Inventory\Models\Producto;
use Modules\Procurement\Models\Devolucion;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 2 — paso 6: reembolsos (PayPal y caja) y devoluciones vinculadas.
 */
class ReembolsoTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private PayPalFalso $paypal;
    private $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->paypal = PayPalFalso::instalar();
        $this->tasaHoy('6.96');
        $this->encargado = $this->usuarioConRol('Encargado');
    }

    /** Pago PayPal recibido de Bs. 200.00 (USD 28.74). */
    private function pagoPayPal(): Pago
    {
        $cliente = $this->cliente();
        $this->alCarrito($cliente, $this->producto(1, '100.00', 5), 2);
        $uuid = $this->actingAs($cliente)->postJson(route('finanzas.paypal.orden'), ['clave_idempotencia' => 'clave-reembolso-0000001'])->json('uuid');
        $this->actingAs($cliente)->postJson(url("/pagos/{$uuid}/captura"))->assertOk();

        return Pago::where('uuid', $uuid)->firstOrFail();
    }

    private function servicio(): ReembolsoService
    {
        return app(ReembolsoService::class);
    }

    public function test_reembolso_total_paypal_solicitado_por_encargado_y_ejecutado_por_admin(): void
    {
        $pago = $this->pagoPayPal();

        $r = $this->servicio()->solicitar($pago, Dinero::deDecimal('200', 'BOB'), 'Cliente desistió', $this->encargado, 'ref-total-1');
        $this->assertSame(EstadoReembolso::Solicitado, $r->estado);
        $this->assertSame('28.74', (string) $r->monto_proveedor);
        $this->assertSame(0, $this->paypal->reembolsosSolicitados);

        $r = $this->servicio()->autorizarYEjecutar($r, $this->admin);

        $this->assertSame(EstadoReembolso::Completado, $r->estado);
        $this->assertSame((int) $this->admin->ci, (int) $r->ci_autorizado_por);
        $this->assertSame(EstadoPago::Reembolsado, $pago->fresh()->estado);
        $this->assertContains('reembolso-' . $r->uuid, $this->paypal->requestIds);
        Http::assertSent(fn (Request $q) => str_contains($q->url(), '/v2/payments/captures/CAP-ORD-1/refund')
            && $q['amount'] === ['value' => '28.74', 'currency_code' => 'USD']);
    }

    public function test_reembolsos_parciales_con_la_tasa_original_y_sin_deriva(): void
    {
        $pago = $this->pagoPayPal();

        $r1 = $this->servicio()->autorizarYEjecutar(
            $this->servicio()->solicitar($pago, Dinero::deDecimal('100', 'BOB'), 'Parcial 1', $this->admin, 'ref-parcial-1'),
            $this->admin
        );
        $this->assertSame('14.37', (string) $r1->monto_proveedor); // 100 / 6.96
        $this->assertSame(EstadoPago::ReembolsadoParcial, $pago->fresh()->estado);

        $r2 = $this->servicio()->autorizarYEjecutar(
            $this->servicio()->solicitar($pago->fresh(), Dinero::deDecimal('100', 'BOB'), 'Parcial 2', $this->admin, 'ref-parcial-2'),
            $this->admin
        );
        // Último reembolso: el USD restante exacto (28.74 − 14.37), no un nuevo redondeo
        $this->assertSame('14.37', (string) $r2->monto_proveedor);
        $this->assertSame(EstadoPago::Reembolsado, $pago->fresh()->estado);
    }

    public function test_no_se_reembolsa_mas_de_lo_recibido(): void
    {
        $pago = $this->pagoPayPal();
        $this->servicio()->solicitar($pago, Dinero::deDecimal('150', 'BOB'), 'Parcial', $this->admin, 'ref-limite-1');

        $this->expectException(\DomainException::class);
        $this->servicio()->solicitar($pago, Dinero::deDecimal('50.01', 'BOB'), 'Excede', $this->admin, 'ref-limite-2');
    }

    public function test_reembolso_idempotente(): void
    {
        $pago = $this->pagoPayPal();

        $a = $this->servicio()->solicitar($pago, Dinero::deDecimal('50', 'BOB'), 'Doble clic', $this->admin, 'ref-idem-1');
        $b = $this->servicio()->solicitar($pago, Dinero::deDecimal('50', 'BOB'), 'Doble clic', $this->admin, 'ref-idem-1');
        $this->assertSame($a->id, $b->id);

        $this->servicio()->autorizarYEjecutar($a, $this->admin);
        $this->servicio()->autorizarYEjecutar($a->fresh(), $this->admin);

        $this->assertSame(1, $this->paypal->reembolsosSolicitados);
        $this->assertSame(1, Reembolso::count());
    }

    public function test_reembolso_incierto_no_se_descarta_y_la_conciliacion_lo_resuelve(): void
    {
        $pago = $this->pagoPayPal();
        $this->paypal->modoReembolso = 'incierto_500';

        $r = $this->servicio()->autorizarYEjecutar(
            $this->servicio()->solicitar($pago, Dinero::deDecimal('200', 'BOB'), 'Timeout', $this->admin, 'ref-incierto-1'),
            $this->admin
        );
        $this->assertSame(EstadoReembolso::Incierto, $r->estado);
        $this->assertSame(EstadoPago::Recibido, $pago->fresh()->estado);

        // El importe queda comprometido mientras es incierto: no se puede volver a reembolsar
        $this->assertSame(0, $this->servicio()->disponible($pago->fresh())->centavos);

        $this->paypal->modoReembolso = 'ok';
        app(ConciliacionService::class)->ejecutar();

        $this->assertSame(EstadoReembolso::Completado, $r->fresh()->estado);
        $this->assertSame(EstadoPago::Reembolsado, $pago->fresh()->estado);
        // Mismo PayPal-Request-Id en el reintento (sin doble reembolso)
        $this->assertSame(2, count(array_filter($this->paypal->requestIds, fn ($id) => $id === 'reembolso-' . $r->uuid)));
    }

    public function test_reembolso_rechazado_por_paypal_libera_el_importe(): void
    {
        $pago = $this->pagoPayPal();
        $this->paypal->modoReembolso = 'rechazado';

        $r = $this->servicio()->autorizarYEjecutar(
            $this->servicio()->solicitar($pago, Dinero::deDecimal('200', 'BOB'), 'Rechazo', $this->admin, 'ref-rechazo-1'),
            $this->admin
        );

        $this->assertSame(EstadoReembolso::Fallido, $r->estado);
        $this->assertSame('200.00', $this->servicio()->disponible($pago->fresh())->decimal());
    }

    public function test_reembolso_en_caja_se_completa_al_confirmar_la_entrega(): void
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $this->abrirCaja($vendedor);
        $this->producto(10, '50.00', 4);
        $cliente = $this->cliente();
        $this->actingAs($vendedor)->postJson('/ventas', [
            'ci_cliente' => $cliente->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'productos' => [['id' => 10, 'cantidad' => 2]],
            'efectivo_recibido' => '100',
            'clave_idempotencia' => 'pos-reembolso-caja-0001',
        ])->assertOk();
        $pago = Pago::firstOrFail();

        $r = $this->servicio()->solicitar($pago, Dinero::deDecimal('50', 'BOB'), 'Producto fallado', $this->encargado, 'ref-caja-1');
        $r = $this->servicio()->autorizarYEjecutar($r, $this->admin);
        $this->assertSame(EstadoReembolso::Autorizado, $r->estado); // falta confirmar la entrega del dinero
        $this->assertNull($r->monto_proveedor);
        $this->assertSame(0, $this->paypal->reembolsosSolicitados);

        $r = $this->servicio()->confirmarEntrega($r, $this->admin);
        $this->assertSame(EstadoReembolso::Completado, $r->estado);
        $this->assertSame(EstadoPago::ReembolsadoParcial, $pago->fresh()->estado);
    }

    public function test_el_administrador_puede_rechazar_un_reembolso(): void
    {
        $pago = $this->pagoPayPal();
        $r = $this->servicio()->solicitar($pago, Dinero::deDecimal('20', 'BOB'), 'Sin justificación', $this->encargado, 'ref-rech-admin');

        $r = $this->servicio()->rechazar($r, $this->admin, 'No corresponde');

        $this->assertSame(EstadoReembolso::Rechazado, $r->estado);
        $this->assertSame('200.00', $this->servicio()->disponible($pago->fresh())->decimal());
    }

    public function test_reembolsos_y_pagos_no_se_eliminan(): void
    {
        $pago = $this->pagoPayPal();
        $r = $this->servicio()->solicitar($pago, Dinero::deDecimal('10', 'BOB'), 'x', $this->admin, 'ref-borrar');

        $this->expectException(\LogicException::class);
        $r->delete();
    }

    // ───────────── Devoluciones ─────────────

    private function ventaEnCajaConDescuento(): array
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $this->abrirCaja($vendedor);
        $this->producto(20, '50.00', 10);
        $cliente = $this->cliente();
        $r = $this->actingAs($vendedor)->postJson('/ventas', [
            'ci_cliente' => $cliente->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::TARJETA),
            'productos' => [['id' => 20, 'cantidad' => 2, 'descuento' => 10]], // neto 90.00
            'referencia' => 'VOUCHER-DEV-1',
            'clave_idempotencia' => 'pos-devolucion-00000001',
        ])->assertOk();

        return [(int) $r->json('nro_factura'), $vendedor];
    }

    private function registrarDevolucion(int $nro, int $cantidad, string $tipo = 'Devolución'): Devolucion
    {
        $this->actingAs($this->admin)->postJson('/devoluciones', [
            'nro_factura' => $nro,
            'tipo' => $tipo,
            'motivo' => 'Prueba de devolución',
            'productos' => [['id' => 20, 'cantidad' => $cantidad]],
        ])->assertOk();

        return Devolucion::latest('id')->firstOrFail();
    }

    public function test_devolucion_no_modifica_la_nota_y_genera_reembolso_vinculado(): void
    {
        [$nro] = $this->ventaEnCajaConDescuento();
        $notaAntes = DB::table('NotaVenta')->where('nro', $nro)->first();
        $lineasAntes = DB::table('detalleNotaVenta')->where('nro_factura', $nro)->get()->toArray();

        $dev = $this->registrarDevolucion($nro, 1);
        $this->actingAs($this->admin)->post("/devoluciones/{$dev->id}/aprobar")->assertSessionHas('success');

        // La nota y sus líneas quedan EXACTAMENTE igual
        $this->assertEquals($notaAntes, DB::table('NotaVenta')->where('nro', $nro)->first());
        $this->assertEquals($lineasAntes, DB::table('detalleNotaVenta')->where('nro_factura', $nro)->get()->toArray());

        $dev->refresh();
        $this->assertSame('Aprobado', $dev->estado);
        $this->assertSame('45.00', (string) $dev->monto_total); // neto 90 / 2 unidades
        $this->assertSame('45.00', (string) $dev->detalles->first()->subtotal);
        $this->assertSame(9, Producto::find(20)->cantidad); // 10 − 2 + 1

        $reembolso = Reembolso::where('devolucion_id', $dev->id)->firstOrFail();
        $this->assertSame('45.00', (string) $reembolso->monto);
        $this->assertSame(EstadoReembolso::Solicitado, $reembolso->estado);
    }

    public function test_no_se_puede_devolver_mas_de_lo_vendido(): void
    {
        [$nro] = $this->ventaEnCajaConDescuento();

        $d1 = $this->registrarDevolucion($nro, 1);
        $this->actingAs($this->admin)->post("/devoluciones/{$d1->id}/aprobar")->assertSessionHas('success');

        $d2 = $this->registrarDevolucion($nro, 2); // 1 + 2 > 2 vendidas
        $this->actingAs($this->admin)->post("/devoluciones/{$d2->id}/aprobar")->assertSessionHas('error');

        $this->assertSame('Pendiente', $d2->fresh()->estado);
        $this->assertSame(9, Producto::find(20)->cantidad);
        $this->assertSame(1, Reembolso::count());
    }

    public function test_garantia_no_genera_reembolso(): void
    {
        [$nro] = $this->ventaEnCajaConDescuento();
        $dev = $this->registrarDevolucion($nro, 1, 'Garantía');

        $this->actingAs($this->admin)->post("/devoluciones/{$dev->id}/aprobar")->assertSessionHas('success');

        $this->assertSame(0, Reembolso::count());
        $this->assertSame('0.00', (string) $dev->fresh()->monto_total);
        $this->assertSame(1, FacturaVenta::count());
    }
}
