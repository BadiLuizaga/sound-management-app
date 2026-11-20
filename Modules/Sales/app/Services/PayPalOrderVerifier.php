<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Http;

/**
 * Verifica en el servidor, contra la API de PayPal, que una orden fue
 * realmente pagada por el monto y la moneda esperados.
 *
 * Nunca se confía en lo que reporta el navegador: el frontend solo envía el
 * ID de la orden y aquí se consulta su estado real.
 */
class PayPalOrderVerifier
{
    public function isConfigured(): bool
    {
        return filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'));
    }

    public function currency(): string
    {
        return strtoupper((string) config('services.paypal.currency', 'USD'));
    }

    /**
     * @return array{order_id:string, capture_id:string, amount:string, currency:string, payer:?string, raw_status:string}
     *
     * @throws \RuntimeException si la orden no es válida para el monto esperado
     */
    public function verify(string $orderId, float $expectedAmount): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('El pago en línea no está configurado.');
        }

        if (!preg_match('/^[A-Z0-9-]{8,64}$/i', $orderId)) {
            throw new \RuntimeException('Identificador de orden inválido.');
        }

        $order = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl() . '/v2/checkout/orders/' . rawurlencode($orderId));

        if ($order->failed()) {
            throw new \RuntimeException('No se pudo verificar la orden con PayPal.');
        }

        $status = (string) $order->json('status');
        if ($status !== 'COMPLETED') {
            throw new \RuntimeException('La orden de pago no está completada.');
        }

        $unit = $order->json('purchase_units.0') ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;

        if (!$capture || ($capture['status'] ?? null) !== 'COMPLETED') {
            throw new \RuntimeException('El pago no fue capturado.');
        }

        $amount = (string) ($capture['amount']['value'] ?? '');
        $currency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
        $expected = number_format($expectedAmount, 2, '.', '');

        // Comparación en centavos enteros (sin errores de coma flotante)
        $pagadoCentavos = is_numeric($amount) ? (int) round(((float) $amount) * 100) : -1;
        $esperadoCentavos = (int) round(((float) $expected) * 100);

        if ($currency !== $this->currency() || $pagadoCentavos !== $esperadoCentavos) {
            throw new \RuntimeException('El monto pagado no coincide con el total del carrito.');
        }

        return [
            'order_id' => $orderId,
            'capture_id' => (string) ($capture['id'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'payer' => $order->json('payer.email_address'),
            'raw_status' => $status,
        ];
    }

    private function accessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth((string) config('services.paypal.client_id'), (string) config('services.paypal.secret'))
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');

        if ($response->failed() || !is_string($token) || $token === '') {
            throw new \RuntimeException('No se pudo autenticar con PayPal.');
        }

        return $token;
    }

    private function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
