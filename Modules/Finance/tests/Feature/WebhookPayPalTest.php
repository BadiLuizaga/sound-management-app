<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Models\WebhookEvento;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\FacturaVenta;
use Tests\TestCase;

/**
 * Etapa 2 — paso 4: webhooks de PayPal con firma verificada e idempotentes.
 */
class WebhookPayPalTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private PayPalFalso $paypal;
    private $clienteUsuario;

    private const CABECERAS = [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-FICTICIO',
        'PAYPAL-TRANSMISSION-ID' => 'TX-1',
        'PAYPAL-TRANSMISSION-SIG' => 'firma-ficticia',
        'PAYPAL-TRANSMISSION-TIME' => '2026-11-20T12:00:00Z',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->paypal = PayPalFalso::instalar();
        $this->tasaHoy('6.96');
        $this->clienteUsuario = $this->cliente();
        $p = $this->producto(1, '100.00', 5);
        $this->alCarrito($this->clienteUsuario, $p, 2);
    }

    private function pagoEnVerificacion(): Pago
    {
        $this->paypal->modoCaptura = 'incierto_500';
        $uuid = $this->actingAs($this->clienteUsuario)->postJson(route('finanzas.paypal.orden'), ['clave_idempotencia' => 'clave-webhook-00000001'])->json('uuid');
        $this->actingAs($this->clienteUsuario)->postJson(url("/pagos/{$uuid}/captura"));

        return Pago::where('uuid', $uuid)->firstOrFail();
    }

    private function enviar(array $evento, array $cabeceras = self::CABECERAS)
    {
        auth()->logout();
        return $this->withHeaders($cabeceras)->postJson('/webhooks/paypal', $evento);
    }

    private function eventoCaptura(Pago $pago, string $id = 'WH-EVT-1', string $tipo = 'PAYMENT.CAPTURE.COMPLETED'): array
    {
        return [
            'id' => $id,
            'event_type' => $tipo,
            'resource' => [
                'id' => 'CAP-' . $pago->referencia_externa,
                'status' => 'COMPLETED',
                'custom_id' => $pago->uuid,
                'amount' => ['currency_code' => 'USD', 'value' => (string) $pago->monto_proveedor],
                'supplementary_data' => ['related_ids' => ['order_id' => $pago->referencia_externa]],
            ],
        ];
    }

    public function test_firma_invalida_400_y_no_procesa(): void
    {
        $pago = $this->pagoEnVerificacion();
        $this->paypal->firmaValida = false;

        $this->enviar($this->eventoCaptura($pago))->assertStatus(400);

        $this->assertSame(EstadoPago::Verificando, $pago->fresh()->estado);
        $this->assertFalse(WebhookEvento::first()->firma_valida);
        $this->assertNull(WebhookEvento::first()->procesado_at);
    }

    public function test_sin_cabeceras_de_firma_no_se_procesa(): void
    {
        $pago = $this->pagoEnVerificacion();

        $this->enviar($this->eventoCaptura($pago), [])->assertStatus(400);
        $this->assertSame(EstadoPago::Verificando, $pago->fresh()->estado);
    }

    public function test_captura_completada_por_webhook_y_evento_repetido_ignorado(): void
    {
        $pago = $this->pagoEnVerificacion();

        $this->enviar($this->eventoCaptura($pago))->assertOk();
        $this->assertSame(EstadoPago::Recibido, $pago->fresh()->estado);
        $this->assertSame(1, FacturaVenta::count());

        $this->enviar($this->eventoCaptura($pago))->assertOk()->assertJson(['message' => 'Evento ya procesado.']);
        $this->assertSame(1, WebhookEvento::count());
        $this->assertSame(1, FacturaVenta::count());
    }

    public function test_registro_previo_con_firma_invalida_no_bloquea_el_evento_autentico(): void
    {
        $pago = $this->pagoEnVerificacion();
        $this->paypal->firmaValida = false;
        $this->enviar($this->eventoCaptura($pago))->assertStatus(400);

        $this->paypal->firmaValida = true;
        $this->enviar($this->eventoCaptura($pago))->assertOk();

        $this->assertSame(EstadoPago::Recibido, $pago->fresh()->estado);
        $this->assertTrue(WebhookEvento::first()->firma_valida);
    }

    public function test_reembolso_hecho_fuera_de_la_aplicacion_queda_registrado(): void
    {
        $pago = $this->pagoEnVerificacion();
        $this->enviar($this->eventoCaptura($pago))->assertOk();
        $pago->refresh();

        $this->enviar([
            'id' => 'WH-EVT-REF',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'REF-EXTERNO-1',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => (string) $pago->monto_proveedor],
                'links' => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/' . $pago->referencia_captura]],
            ],
        ])->assertOk();

        $r = Reembolso::where('referencia_externa', 'REF-EXTERNO-1')->firstOrFail();
        $this->assertSame(Reembolso::ORIGEN_EXTERNO, $r->origen);
        $this->assertSame('200.00', (string) $r->monto);
        $this->assertSame(EstadoPago::Reembolsado, $pago->fresh()->estado);
    }

    public function test_reverso_marca_revertido_y_conserva_el_pago(): void
    {
        $pago = $this->pagoEnVerificacion();
        $this->enviar($this->eventoCaptura($pago))->assertOk();
        $pago->refresh();

        $this->enviar([
            'id' => 'WH-EVT-REV',
            'event_type' => 'PAYMENT.CAPTURE.REVERSED',
            'resource' => [
                'id' => 'REV-1',
                'links' => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/' . $pago->referencia_captura]],
            ],
        ])->assertOk();

        $pago->refresh();
        $this->assertSame(EstadoPago::Revertido, $pago->estado);
        $this->assertStringContainsString('revirtió', $pago->motivo_revision);
        $this->assertSame(1, Pago::count());
    }

    public function test_si_no_se_puede_verificar_la_firma_responde_503_para_que_paypal_reintente(): void
    {
        $pago = $this->pagoEnVerificacion();
        $this->paypal->verificacionCaida = true;

        $this->enviar($this->eventoCaptura($pago))->assertStatus(503);
        $this->assertSame(EstadoPago::Verificando, $pago->fresh()->estado);
    }
}
