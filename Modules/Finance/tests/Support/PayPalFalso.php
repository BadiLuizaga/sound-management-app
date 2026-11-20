<?php

namespace Modules\Finance\Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayPal simulado para las pruebas (Http::fake). Nunca hay red, credenciales
 * reales ni cobros. Reproduce las respuestas de Orders v2, Payments v2 y
 * Notifications según la documentación pública de la API.
 *
 * Modos de captura: ok | monto_distinto | custom_distinto | incierto_500 | incierto_conexion | rechazada | pendiente
 * Estado de consulta de orden: COMPLETED | APPROVED | CREATED | 500 | 404
 * Modos de reembolso: ok | pendiente | incierto_500 | rechazado
 */
class PayPalFalso
{
    public string $modoCaptura = 'ok';
    public string $estadoConsulta = 'COMPLETED';
    public string $modoReembolso = 'ok';
    public string $modoCrear = 'ok';
    public bool $firmaValida = true;
    public bool $verificacionCaida = false;

    /** @var array<string, array{custom_id:string, amount:array}> */
    public array $ordenes = [];
    public int $capturasSolicitadas = 0;
    public int $reembolsosSolicitados = 0;
    public array $requestIds = [];
    private int $secuencia = 0;

    public static function instalar(): self
    {
        $falso = new self();
        config([
            'services.paypal.client_id' => 'cliente-ficticio-pruebas',
            'services.paypal.secret' => 'secreto-ficticio-pruebas',
            'services.paypal.webhook_id' => 'WH-FICTICIO',
            'services.paypal.mode' => 'sandbox',
            'services.paypal.currency' => 'USD',
        ]);

        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $falso->responder($r));

        return $falso;
    }

    public function responder(Request $r)
    {
        $url = $r->url();
        $ruta = parse_url($url, PHP_URL_PATH);
        $metodo = $r->method();

        if (!str_starts_with($url, 'https://api-m.sandbox.paypal.com/')) {
            return Http::response(['error' => 'host no permitido en pruebas'], 599);
        }

        if ($r->hasHeader('PayPal-Request-Id')) {
            $this->requestIds[] = $r->header('PayPal-Request-Id')[0];
        }

        if ($ruta === '/v1/oauth2/token') {
            return Http::response(['access_token' => 'token-ficticio', 'expires_in' => 3600]);
        }

        if ($ruta === '/v1/notifications/verify-webhook-signature') {
            if ($this->verificacionCaida) {
                return Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503);
            }
            return Http::response(['verification_status' => $this->firmaValida ? 'SUCCESS' : 'FAILURE']);
        }

        if ($metodo === 'POST' && $ruta === '/v2/checkout/orders') {
            if ($this->modoCrear === 'rechazada') {
                return Http::response(['name' => 'INVALID_REQUEST', 'message' => 'Solicitud inválida'], 400);
            }
            $cuerpo = $r->data();
            $requestId = $r->header('PayPal-Request-Id')[0] ?? null;
            // Idempotencia de PayPal: misma PayPal-Request-Id → misma orden
            foreach ($this->ordenes as $id => $o) {
                if ($o['request_id'] === $requestId) {
                    return Http::response($this->orden($id, 'CREATED'), 201);
                }
            }
            $id = 'ORD-' . (++$this->secuencia);
            $unidad = $cuerpo['purchase_units'][0];
            $this->ordenes[$id] = ['custom_id' => $unidad['custom_id'], 'amount' => $unidad['amount'], 'request_id' => $requestId];

            return Http::response($this->orden($id, 'CREATED'), 201);
        }

        if ($metodo === 'POST' && preg_match('#^/v2/checkout/orders/([^/]+)/capture$#', $ruta, $m)) {
            $this->capturasSolicitadas++;
            return match ($this->modoCaptura) {
                'incierto_500' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
                'incierto_conexion' => throw new ConnectionException('timeout simulado'),
                'rechazada' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422),
                'pendiente' => Http::response($this->orden($m[1], 'COMPLETED', 'PENDING')),
                'monto_distinto' => Http::response($this->orden($m[1], 'COMPLETED', 'COMPLETED', '+0.01')),
                'custom_distinto' => Http::response($this->orden($m[1], 'COMPLETED', 'COMPLETED', null, 'otro-custom')),
                default => Http::response($this->orden($m[1], 'COMPLETED', 'COMPLETED')),
            };
        }

        if ($metodo === 'GET' && preg_match('#^/v2/checkout/orders/([^/]+)$#', $ruta, $m)) {
            return match ($this->estadoConsulta) {
                '500' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
                '404' => Http::response(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'no existe'], 404),
                'COMPLETED' => Http::response($this->orden($m[1], 'COMPLETED', 'COMPLETED')),
                default => Http::response($this->orden($m[1], $this->estadoConsulta)),
            };
        }

        if ($metodo === 'POST' && preg_match('#^/v2/payments/captures/([^/]+)/refund$#', $ruta, $m)) {
            $this->reembolsosSolicitados++;
            $monto = $r->data()['amount'];
            return match ($this->modoReembolso) {
                'incierto_500' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
                'rechazado' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Refund refused'], 422),
                'pendiente' => Http::response(['id' => 'REF-' . md5($r->header('PayPal-Request-Id')[0]), 'status' => 'PENDING', 'amount' => $monto], 201),
                default => Http::response(['id' => 'REF-' . md5($r->header('PayPal-Request-Id')[0]), 'status' => 'COMPLETED', 'amount' => $monto], 201),
            };
        }

        if ($metodo === 'GET' && preg_match('#^/v2/payments/refunds/([^/]+)$#', $ruta, $m)) {
            return Http::response(['id' => $m[1], 'status' => 'COMPLETED', 'amount' => ['value' => '1.00', 'currency_code' => 'USD']]);
        }

        return Http::response(['name' => 'NOT_FOUND_EN_FALSO', 'ruta' => $ruta], 404);
    }

    public function orden(string $id, string $estado, ?string $estadoCaptura = null, ?string $ajusteMonto = null, ?string $customForzado = null): array
    {
        $o = $this->ordenes[$id] ?? ['custom_id' => 'desconocido', 'amount' => ['currency_code' => 'USD', 'value' => '1.00']];
        $unidad = ['reference_id' => $o['custom_id'], 'custom_id' => $customForzado ?? $o['custom_id'], 'amount' => $o['amount']];

        if ($estadoCaptura !== null) {
            $valor = $o['amount']['value'];
            if ($ajusteMonto === '+0.01') {
                $valor = number_format(((float) $valor) + 0.01, 2, '.', '');
            }
            $unidad['payments'] = ['captures' => [[
                'id' => 'CAP-' . $id,
                'status' => $estadoCaptura,
                'amount' => ['currency_code' => $o['amount']['currency_code'], 'value' => $valor],
                'custom_id' => $customForzado ?? $o['custom_id'],
            ]]];
        }

        return ['id' => $id, 'status' => $estado, 'purchase_units' => [$unidad]];
    }
}
