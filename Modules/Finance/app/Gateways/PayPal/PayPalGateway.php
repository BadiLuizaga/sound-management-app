<?php

namespace Modules\Finance\Gateways\PayPal;

use Modules\Finance\Gateways\GatewayIncierto;
use Modules\Finance\Gateways\PaymentGateway;
use Modules\Finance\Gateways\Resultados\ResultadoOrden;
use Modules\Finance\Gateways\Resultados\ResultadoReembolso;
use Modules\Finance\Models\Pago;
use Modules\Finance\Support\Dinero;

class PayPalGateway implements PaymentGateway
{
    public function __construct(private readonly PayPalClient $cliente)
    {
    }

    public function configurado(): bool
    {
        return filled(config('services.paypal.client_id'))
            && filled(config('services.paypal.secret'))
            && filled(config('services.paypal.webhook_id'));
    }

    public function moneda(): string
    {
        return strtoupper((string) config('services.paypal.currency', 'USD'));
    }

    public function crearOrden(Pago $pago): ResultadoOrden
    {
        $json = $this->cliente->enviar('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $pago->uuid,
                'custom_id' => $pago->uuid,
                'invoice_id' => $pago->uuid,
                'description' => 'Sound Management - pago ' . $pago->uuid,
                'amount' => [
                    'currency_code' => $pago->moneda_proveedor,
                    'value' => $pago->importeProveedor()->decimal(),
                ],
            ]],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
            ],
        ], 'orden-' . $pago->uuid);

        return $this->interpretarOrden($json);
    }

    public function capturarOrden(string $orderId, string $requestId): ResultadoOrden
    {
        return $this->interpretarOrden(
            $this->cliente->enviar('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [], $requestId)
        );
    }

    public function consultarOrden(string $orderId): ResultadoOrden
    {
        return $this->interpretarOrden(
            $this->cliente->enviar('GET', '/v2/checkout/orders/' . rawurlencode($orderId))
        );
    }

    public function reembolsarCaptura(string $captureId, Dinero $monto, string $requestId, string $nota): ResultadoReembolso
    {
        return $this->interpretarReembolso($this->cliente->enviar('POST', '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', [
            'amount' => ['value' => $monto->decimal(), 'currency_code' => $monto->moneda],
            'note_to_payer' => mb_substr($nota, 0, 255),
        ], $requestId));
    }

    public function consultarReembolso(string $refundId): ResultadoReembolso
    {
        return $this->interpretarReembolso($this->cliente->enviar('GET', '/v2/payments/refunds/' . rawurlencode($refundId)));
    }

    public function verificarFirmaWebhook(array $cabeceras, array $evento): bool
    {
        $h = array_change_key_case($cabeceras, CASE_LOWER);
        $requeridas = ['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'];
        foreach ($requeridas as $clave) {
            if (empty($h[$clave])) {
                return false;
            }
        }

        $json = $this->cliente->enviar('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $h['paypal-auth-algo'],
            'cert_url' => $h['paypal-cert-url'],
            'transmission_id' => $h['paypal-transmission-id'],
            'transmission_sig' => $h['paypal-transmission-sig'],
            'transmission_time' => $h['paypal-transmission-time'],
            'webhook_id' => (string) config('services.paypal.webhook_id'),
            'webhook_event' => $evento,
        ]);

        return ($json['verification_status'] ?? null) === 'SUCCESS';
    }

    private function interpretarOrden(array $json): ResultadoOrden
    {
        if (empty($json['id']) || empty($json['status'])) {
            throw new GatewayIncierto('Respuesta de orden incompleta.');
        }

        $unidad = $json['purchase_units'][0] ?? [];
        $captura = $unidad['payments']['captures'][0] ?? null;
        $monto = null;
        if ($captura && isset($captura['amount']['value'], $captura['amount']['currency_code']) && is_numeric($captura['amount']['value'])) {
            $monto = Dinero::deDecimal((string) $captura['amount']['value'], (string) $captura['amount']['currency_code']);
        }

        return new ResultadoOrden(
            orderId: (string) $json['id'],
            estadoOrden: (string) $json['status'],
            customId: $unidad['custom_id'] ?? ($captura['custom_id'] ?? null),
            capturaId: $captura['id'] ?? null,
            estadoCaptura: $captura['status'] ?? null,
            montoCapturado: $monto,
            respuesta: $this->sanear($json),
        );
    }

    private function interpretarReembolso(array $json): ResultadoReembolso
    {
        if (empty($json['id']) || empty($json['status'])) {
            throw new GatewayIncierto('Respuesta de reembolso incompleta.');
        }

        $monto = isset($json['amount']['value'], $json['amount']['currency_code'])
            ? Dinero::deDecimal((string) $json['amount']['value'], (string) $json['amount']['currency_code'])
            : null;

        return new ResultadoReembolso((string) $json['id'], (string) $json['status'], $monto, [
            'id' => $json['id'], 'status' => $json['status'], 'amount' => $json['amount'] ?? null,
        ]);
    }

    /**
     * Solo se guardan los datos necesarios para conciliar (sin datos personales del pagador).
     */
    private function sanear(array $json): array
    {
        $unidades = [];
        foreach ((array) ($json['purchase_units'] ?? []) as $u) {
            $unidades[] = [
                'reference_id' => $u['reference_id'] ?? null,
                'custom_id' => $u['custom_id'] ?? null,
                'amount' => $u['amount'] ?? null,
                'captures' => array_map(fn ($c) => [
                    'id' => $c['id'] ?? null,
                    'status' => $c['status'] ?? null,
                    'amount' => $c['amount'] ?? null,
                    'custom_id' => $c['custom_id'] ?? null,
                ], (array) ($u['payments']['captures'] ?? [])),
            ];
        }

        return ['id' => $json['id'] ?? null, 'status' => $json['status'] ?? null, 'purchase_units' => $unidades];
    }
}
