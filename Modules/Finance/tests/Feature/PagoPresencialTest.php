<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 2 — paso 5: cobros en caja (POS).
 * Venta de prueba: 2 × Bs. 50.00 = Bs. 100.00.
 */
class PagoPresencialTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private $vendedor;
    private $clienteUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->vendedor = $this->usuarioConRol('Vendedor');
        $this->clienteUsuario = $this->cliente();
        $this->producto(10, '50.00', 4);
        $this->abrirCaja($this->vendedor);
    }

    private function vender(string $codigo, array $extra = [], string $clave = 'pos-clave-0000000001')
    {
        return $this->actingAs($this->vendedor)->postJson('/ventas', array_merge([
            'ci_cliente' => $this->clienteUsuario->ci,
            'id_pago' => MetodoPago::idPorCodigo($codigo),
            'productos' => [['id' => 10, 'cantidad' => 2, 'descuento' => 0]],
            'clave_idempotencia' => $clave,
        ], $extra));
    }

    public function test_efectivo_registra_pago_recibido_y_venta_con_cambio(): void
    {
        $r = $this->vender(MetodoPago::EFECTIVO, ['efectivo_recibido' => '150'])->assertOk()
            ->assertJson(['success' => true, 'pendiente' => false, 'cambio' => '50.00']);

        $pago = Pago::firstOrFail();
        $this->assertSame(EstadoPago::Recibido, $pago->estado);
        $this->assertSame(EstadoAplicacion::Aplicado, $pago->estado_aplicacion);
        $this->assertSame((int) $r->json('nro_factura'), (int) $pago->nro_factura);
        $this->assertSame('100.00', (string) $pago->monto);
        $this->assertNotNull($pago->caja_id);
        $this->assertSame(2, Producto::find(10)->cantidad);
        $this->assertSame((int) $this->vendedor->ci, (int) FacturaVenta::first()->ci_empleado);
    }

    public function test_efectivo_insuficiente_rechazado_sin_registrar_nada(): void
    {
        $this->vender(MetodoPago::EFECTIVO, ['efectivo_recibido' => '99.99'])->assertStatus(422);
        $this->assertSame(0, Pago::count());
        $this->assertSame(0, FacturaVenta::count());
    }

    public function test_tarjeta_exige_referencia(): void
    {
        $this->vender(MetodoPago::TARJETA)->assertStatus(422);
        $this->assertSame(0, Pago::count());

        $this->vender(MetodoPago::TARJETA, ['referencia' => 'aut-123456'])->assertOk()->assertJson(['pendiente' => false]);
        $this->assertSame('AUT-123456', Pago::first()->referencia_externa);
    }

    public function test_referencia_repetida_rechazada(): void
    {
        $this->vender(MetodoPago::TARJETA, ['referencia' => 'AUT-1'], 'pos-clave-0000000001')->assertOk();
        $this->vender(MetodoPago::TARJETA, ['referencia' => 'aut-1'], 'pos-clave-0000000002')->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        $this->assertSame(1, Pago::count());
        $this->assertSame(1, FacturaVenta::count());
    }

    public function test_transferencia_queda_pendiente_sin_venta_ni_descuento_de_stock(): void
    {
        $this->vender(MetodoPago::TRANSFERENCIA, ['referencia' => 'TRX-998877'])->assertOk()->assertJson(['pendiente' => true]);

        $pago = Pago::firstOrFail();
        $this->assertSame(EstadoPago::PendienteVerificacion, $pago->estado);
        $this->assertNull($pago->recibido_at);
        $this->assertSame(0, FacturaVenta::count());
        $this->assertSame(4, Producto::find(10)->cantidad);
    }

    public function test_qr_sin_referencia_rechazado(): void
    {
        $this->vender(MetodoPago::QR)->assertStatus(422);
        $this->assertSame(0, Pago::count());
    }

    public function test_verificar_transferencia_genera_la_venta(): void
    {
        $this->vender(MetodoPago::QR, ['referencia' => 'QR-0001'])->assertOk();
        $pago = Pago::firstOrFail();
        $encargado = $this->usuarioConRol('Encargado');

        app(PagoService::class)->verificar($pago, $encargado);

        $pago->refresh();
        $this->assertSame(EstadoPago::Recibido, $pago->estado);
        $this->assertSame(EstadoAplicacion::Aplicado, $pago->estado_aplicacion);
        $this->assertSame((int) $encargado->ci, (int) $pago->ci_verificado_por);
        $this->assertSame(1, FacturaVenta::count());
        $this->assertSame(2, Producto::find(10)->cantidad);
    }

    public function test_rechazar_transferencia_no_genera_venta(): void
    {
        $this->vender(MetodoPago::TRANSFERENCIA, ['referencia' => 'TRX-NO-LLEGO'])->assertOk();
        $pago = Pago::firstOrFail();

        app(PagoService::class)->rechazar($pago, $this->admin, 'El ingreso no figura en el extracto');

        $pago->refresh();
        $this->assertSame(EstadoPago::Rechazado, $pago->estado);
        $this->assertSame(EstadoAplicacion::NoAplica, $pago->estado_aplicacion);
        $this->assertSame(0, FacturaVenta::count());
        $this->assertSame(4, Producto::find(10)->cantidad);
    }

    public function test_verificada_sin_stock_conserva_el_pago_en_revision(): void
    {
        $this->vender(MetodoPago::TRANSFERENCIA, ['referencia' => 'TRX-SIN-STOCK'])->assertOk();
        Producto::where('idproducto', 10)->update(['cantidad' => 1]);
        $pago = Pago::firstOrFail();

        app(PagoService::class)->verificar($pago, $this->admin);

        $pago->refresh();
        $this->assertSame(EstadoPago::Recibido, $pago->estado);
        $this->assertSame(EstadoAplicacion::RequiereRevision, $pago->estado_aplicacion);
        $this->assertSame(0, FacturaVenta::count());
    }

    public function test_doble_envio_del_pos_no_duplica(): void
    {
        $this->vender(MetodoPago::EFECTIVO, ['efectivo_recibido' => '100'])->assertOk();
        $this->vender(MetodoPago::EFECTIVO, ['efectivo_recibido' => '100'])->assertOk();

        $this->assertSame(1, Pago::count());
        $this->assertSame(1, FacturaVenta::count());
        $this->assertSame(2, Producto::find(10)->cantidad);
    }

    public function test_sin_caja_abierta_no_se_cobra(): void
    {
        $otro = $this->usuarioConRol('Vendedor');

        $this->actingAs($otro)->postJson('/ventas', [
            'ci_cliente' => $this->clienteUsuario->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'productos' => [['id' => 10, 'cantidad' => 1]],
            'efectivo_recibido' => '50',
            'clave_idempotencia' => 'pos-clave-sincaja-00001',
        ])->assertStatus(422)->assertJsonFragment(['success' => false]);

        $this->assertSame(0, Pago::count());
    }

    public function test_sin_stock_en_efectivo_no_se_registra_nada(): void
    {
        $this->actingAs($this->vendedor)->postJson('/ventas', [
            'ci_cliente' => $this->clienteUsuario->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'productos' => [['id' => 10, 'cantidad' => 9]],
            'efectivo_recibido' => '500',
            'clave_idempotencia' => 'pos-clave-sinstock-0001',
        ])->assertStatus(422);

        $this->assertSame(0, Pago::count());
        $this->assertSame(0, FacturaVenta::count());
        $this->assertSame(4, Producto::find(10)->cantidad);
    }

    public function test_paypal_no_se_acepta_en_caja(): void
    {
        $this->vender(MetodoPago::PAYPAL)->assertStatus(422);
        $this->assertSame(0, Pago::count());
    }
}
