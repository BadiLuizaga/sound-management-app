<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Finance\Aplicadores\AplicadorVentaEnLinea;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\PagoEvento;
use Modules\Finance\Services\ConciliacionService;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\Carrito;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 2 — paso 4: pago en línea con PayPal (orden y captura en el servidor).
 * Carrito de prueba: 2 × Bs. 100.00 = Bs. 200.00; tasa 6.96 → USD 28.74.
 */
class PagoEnLineaTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private PayPalFalso $paypal;
    private $clienteUsuario;
    private Producto $prod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->paypal = PayPalFalso::instalar();
        $this->tasaHoy('6.96');
        $this->clienteUsuario = $this->cliente();
        $this->prod = $this->producto(1, '100.00', 5);
        $this->alCarrito($this->clienteUsuario, $this->prod, 2);
    }

    private function crearOrden(string $clave = 'clave-prueba-0000000001')
    {
        return $this->actingAs($this->clienteUsuario)->postJson(route('finanzas.paypal.orden'), ['clave_idempotencia' => $clave]);
    }

    private function capturar(string $uuid, $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->clienteUsuario)->postJson(url("/pagos/{$uuid}/captura"));
    }

    public function test_crear_orden_en_el_servidor_con_usd_explicito_y_sin_tocar_stock(): void
    {
        $r = $this->crearOrden()->assertOk();
        $pago = Pago::where('uuid', $r->json('uuid'))->firstOrFail();

        $this->assertSame(EstadoPago::OrdenCreada, $pago->estado);
        $this->assertSame('200.00', (string) $pago->monto);
        $this->assertSame('28.74', (string) $pago->monto_proveedor);
        $this->assertSame('USD', $pago->moneda_proveedor);
        $this->assertSame('6.960000', (string) $pago->tasa_aplicada);
        $this->assertSame('ORD-1', $r->json('order_id'));

        Http::assertSent(function (Request $req) use ($pago) {
            return str_ends_with($req->url(), '/v2/checkout/orders')
                && $req['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '28.74']
                && $req['purchase_units'][0]['custom_id'] === $pago->uuid
                && $req->header('PayPal-Request-Id')[0] === 'orden-' . $pago->uuid;
        });

        // Ningún bloqueo de inventario mientras el cliente paga
        $this->assertSame(5, Producto::find(1)->cantidad);
        $this->assertSame(0, FacturaVenta::count());
    }

    public function test_misma_clave_devuelve_el_mismo_intento(): void
    {
        $a = $this->crearOrden('clave-repetida-000000001')->json('uuid');
        $b = $this->crearOrden('clave-repetida-000000001')->json('uuid');

        $this->assertSame($a, $b);
        $this->assertSame(1, Pago::count());
        $this->assertCount(1, $this->paypal->ordenes);
    }

    public function test_captura_correcta_registra_el_pago_y_luego_la_venta(): void
    {
        $uuid = $this->crearOrden()->json('uuid');

        $this->capturar($uuid)->assertOk()
            ->assertJson(['estado' => 'recibido', 'estado_aplicacion' => 'aplicado']);

        $pago = Pago::where('uuid', $uuid)->first();
        $this->assertSame('CAP-ORD-1', $pago->referencia_captura);
        $this->assertNotNull($pago->nro_factura);

        $venta = FacturaVenta::find($pago->nro_factura);
        $this->assertSame(MetodoPago::idPorCodigo(MetodoPago::PAYPAL), (int) $venta->id_pago);
        $this->assertSame('200.00', number_format((float) $venta->total, 2, '.', ''));
        $this->assertSame(3, Producto::find(1)->cantidad);
        $this->assertSame(0, Carrito::where('ci_usuario', $this->clienteUsuario->ci)->count());

        $this->assertSame(
            ['creado', 'orden_creada', 'captura_solicitada', 'recibido_captura', 'aplicado'],
            PagoEvento::where('pago_id', $pago->id)->orderBy('id')->pluck('tipo')->all()
        );
    }

    public function test_lo_que_diga_el_navegador_no_cambia_el_estado(): void
    {
        $uuid = $this->crearOrden()->json('uuid');

        // Aprobado en el navegador pero sin captura en el servidor: sigue pendiente
        $this->actingAs($this->clienteUsuario)->getJson(url("/pagos/{$uuid}/estado"))->assertJson(['estado' => 'orden_creada']);

        // El cliente "afirma" que pagó, pero PayPal rechaza la captura: manda PayPal
        $this->paypal->modoCaptura = 'rechazada';
        $this->actingAs($this->clienteUsuario)
            ->postJson(url("/pagos/{$uuid}/captura"), ['estado' => 'recibido', 'aprobado' => true, 'monto' => '0.01'])
            ->assertJson(['estado' => 'fallido']);

        $this->assertSame(EstadoPago::Fallido, Pago::where('uuid', $uuid)->first()->estado);
        $this->assertSame(0, FacturaVenta::count());
        $this->assertSame(5, Producto::find(1)->cantidad);
    }

    public function test_doble_captura_no_duplica_cobro_ni_venta(): void
    {
        $uuid = $this->crearOrden()->json('uuid');

        $this->capturar($uuid)->assertOk();
        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'recibido']);

        $this->assertSame(1, $this->paypal->capturasSolicitadas);
        $this->assertSame(1, FacturaVenta::count());
        $this->assertSame(3, Producto::find(1)->cantidad);
    }

    public function test_otro_usuario_no_puede_capturar_ni_ver_un_pago_ajeno(): void
    {
        $uuid = $this->crearOrden()->json('uuid');
        $otro = $this->cliente();

        $this->capturar($uuid, $otro)->assertForbidden();
        $this->actingAs($otro)->getJson(url("/pagos/{$uuid}/estado"))->assertForbidden();
        $this->assertSame(0, $this->paypal->capturasSolicitadas);
    }

    public function test_captura_con_monto_distinto_queda_recibida_en_revision_sin_venta(): void
    {
        $this->paypal->modoCaptura = 'monto_distinto';
        $uuid = $this->crearOrden()->json('uuid');

        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'recibido', 'estado_aplicacion' => 'requiere_revision']);

        $pago = Pago::where('uuid', $uuid)->first();
        $this->assertStringContainsString('monto o moneda distintos', $pago->motivo_revision);
        $this->assertSame(0, FacturaVenta::count());
        $this->assertSame(5, Producto::find(1)->cantidad);
    }

    public function test_captura_con_custom_id_ajeno_queda_en_revision(): void
    {
        $this->paypal->modoCaptura = 'custom_distinto';
        $uuid = $this->crearOrden()->json('uuid');

        $this->capturar($uuid)->assertJson(['estado' => 'recibido', 'estado_aplicacion' => 'requiere_revision']);
        $this->assertStringContainsString('custom_id', Pago::where('uuid', $uuid)->first()->motivo_revision);
    }

    public function test_respuesta_incierta_deja_verificando_y_la_conciliacion_lo_resuelve(): void
    {
        $this->paypal->modoCaptura = 'incierto_500';
        $uuid = $this->crearOrden()->json('uuid');

        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'verificando']);
        $this->assertSame(0, FacturaVenta::count());

        $this->paypal->estadoConsulta = 'COMPLETED';
        app(ConciliacionService::class)->ejecutar();

        $pago = Pago::where('uuid', $uuid)->first();
        $this->assertSame(EstadoPago::Recibido, $pago->estado);
        $this->assertSame(EstadoAplicacion::Aplicado, $pago->estado_aplicacion);
        $this->assertSame(1, FacturaVenta::count());
    }

    public function test_error_de_conexion_en_la_captura_tambien_es_incierto(): void
    {
        $this->paypal->modoCaptura = 'incierto_conexion';
        $uuid = $this->crearOrden()->json('uuid');

        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'verificando']);
    }

    public function test_un_pago_verificando_nunca_expira(): void
    {
        $this->paypal->modoCaptura = 'incierto_500';
        $uuid = $this->crearOrden()->json('uuid');
        $this->capturar($uuid);

        Pago::where('uuid', $uuid)->update(['expira_at' => now()->subDay(), 'updated_at' => now()->subDays(2)]);
        $this->paypal->estadoConsulta = '500'; // PayPal sigue sin responder

        app(ConciliacionService::class)->ejecutar();

        $pago = Pago::where('uuid', $uuid)->first();
        $this->assertSame(EstadoPago::Verificando, $pago->estado);
        $this->assertTrue(PagoEvento::where('pago_id', $pago->id)->where('tipo', 'alerta_verificando_prolongado')->exists());
    }

    public function test_sin_stock_tras_el_cobro_el_pago_se_conserva_en_revision(): void
    {
        $uuid = $this->crearOrden()->json('uuid');
        Producto::where('idproducto', 1)->update(['cantidad' => 1]); // otro cliente compró mientras pagaba

        $this->capturar($uuid)->assertJson(['estado' => 'recibido', 'estado_aplicacion' => 'requiere_revision']);

        $pago = Pago::where('uuid', $uuid)->first();
        $this->assertStringContainsString('Stock insuficiente', $pago->motivo_revision);
        $this->assertSame(1, Producto::find(1)->cantidad);
        $this->assertSame(0, FacturaVenta::count());
    }

    public function test_un_fallo_tecnico_al_aplicar_no_borra_el_pago(): void
    {
        $this->app->bind(AplicadorVentaEnLinea::class, fn () => new class extends AplicadorVentaEnLinea {
            public function aplicar(Pago $pago): array
            {
                throw new \RuntimeException('fallo simulado de base de datos');
            }
        });

        $uuid = $this->crearOrden()->json('uuid');
        $this->capturar($uuid)->assertOk();

        $pago = Pago::where('uuid', $uuid)->first();
        $this->assertSame(EstadoPago::Recibido, $pago->estado);
        $this->assertSame(EstadoAplicacion::RequiereRevision, $pago->estado_aplicacion);
        $this->assertSame('CAP-ORD-1', $pago->referencia_captura);
    }

    public function test_captura_rechazada_por_paypal_queda_fallida(): void
    {
        $this->paypal->modoCaptura = 'rechazada';
        $uuid = $this->crearOrden()->json('uuid');

        $this->capturar($uuid)->assertJson(['estado' => 'fallido']);
        $this->assertSame(0, FacturaVenta::count());
    }

    public function test_expiracion_solo_tras_consultar_a_paypal(): void
    {
        // Intento sin orden
        $iniciado = app(\Modules\Finance\Services\PagoService::class)->iniciarEnLinea(
            $this->clienteUsuario, \Modules\Finance\Support\Dinero::deDecimal('200', 'BOB'),
            ['tipo' => 'venta_en_linea', 'items' => []], 'clave-iniciada-0000000001'
        );
        // Orden creada pero nunca aprobada
        $sinAprobar = $this->crearOrden('clave-sin-aprobar-000001')->json('uuid');
        Pago::query()->update(['expira_at' => now()->subMinute()]);

        $this->paypal->estadoConsulta = 'CREATED';
        app(ConciliacionService::class)->ejecutar();

        $this->assertSame(EstadoPago::Expirado, $iniciado->fresh()->estado);
        $this->assertSame(EstadoPago::Expirado, Pago::where('uuid', $sinAprobar)->first()->estado);
        $this->assertSame(5, Producto::find(1)->cantidad);
    }

    public function test_orden_aprobada_pero_no_capturada_la_captura_el_servidor(): void
    {
        $uuid = $this->crearOrden()->json('uuid');
        Pago::where('uuid', $uuid)->update(['expira_at' => now()->subMinute()]);

        $this->paypal->estadoConsulta = 'APPROVED';
        app(ConciliacionService::class)->ejecutar();

        $this->assertSame(EstadoPago::Recibido, Pago::where('uuid', $uuid)->first()->estado);
        $this->assertSame(1, FacturaVenta::count());
    }

    public function test_si_paypal_no_responde_al_conciliar_no_se_expira(): void
    {
        $uuid = $this->crearOrden()->json('uuid');
        Pago::where('uuid', $uuid)->update(['expira_at' => now()->subMinute()]);

        $this->paypal->estadoConsulta = '500';
        app(ConciliacionService::class)->ejecutar();

        $this->assertSame(EstadoPago::OrdenCreada, Pago::where('uuid', $uuid)->first()->estado);
    }

    public function test_configuracion_incompleta_no_llama_a_paypal(): void
    {
        config(['services.paypal.secret' => null]);

        $this->crearOrden()->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(0, Pago::count());
    }

    public function test_el_checkout_antiguo_ya_no_registra_ventas(): void
    {
        $this->actingAs($this->clienteUsuario)
            ->post('/carrito/checkout', ['paypal_order_id' => 'ORD-INVENTADA'])
            ->assertRedirect(route('carrito.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, FacturaVenta::count());
        $this->assertSame(0, Pago::count());
    }

    public function test_el_carrito_muestra_bob_usd_y_tasa(): void
    {
        $this->actingAs($this->clienteUsuario)->get('/carrito')
            ->assertOk()
            ->assertSee('Bs. 200.00', false)
            ->assertSee('USD 28.74', false)
            ->assertSee('6.960000', false);
    }
}
