<?php

namespace Modules\Finance\Gateways\PayPal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Finance\Gateways\GatewayIncierto;
use Modules\Finance\Gateways\GatewayRechazo;

/**
 * Cliente HTTP de la API REST de PayPal (Orders v2, Payments v2, Notifications).
 * El secreto solo se usa aquí, en el servidor.
 */
class PayPalClient
{
    private const TIMEOUT = 20;

    public function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * @throws GatewayIncierto|GatewayRechazo
     */
    public function enviar(string $metodo, string $ruta, ?array $cuerpo = null, ?string $requestId = null): array
    {
        $respuesta = $this->peticion($metodo, $ruta, $cuerpo, $requestId);

        // Token caducado o revocado: se renueva una vez
        if ($respuesta->status() === 401) {
            Cache::forget($this->claveToken());
            $respuesta = $this->peticion($metodo, $ruta, $cuerpo, $requestId);
        }

        if ($respuesta->serverError()) {
            throw new GatewayIncierto("PayPal respondió {$respuesta->status()} en {$ruta}.");
        }

        if ($respuesta->clientError()) {
            $json = (array) $respuesta->json();
            throw new GatewayRechazo(
                (string) ($json['message'] ?? "PayPal rechazó la operación ({$respuesta->status()})."),
                (string) ($json['name'] ?? $respuesta->status()),
                ['name' => $json['name'] ?? null, 'debug_id' => $json['debug_id'] ?? null, 'details' => $json['details'] ?? null]
            );
        }

        $json = $respuesta->json();
        if (!is_array($json)) {
            throw new GatewayIncierto("Respuesta ilegible de PayPal en {$ruta}.");
        }

        return $json;
    }

    private function peticion(string $metodo, string $ruta, ?array $cuerpo, ?string $requestId): Response
    {
        try {
            $req = Http::withToken($this->token())
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->connectTimeout(10);

            if ($requestId !== null) {
                $req = $req->withHeaders([
                    'PayPal-Request-Id' => $requestId,
                    'Prefer' => 'return=representation',
                ]);
            }

            $url = $this->baseUrl() . $ruta;

            return strtoupper($metodo) === 'GET'
                ? $req->get($url)
                : $req->asJson()->send(strtoupper($metodo), $url, ['json' => $cuerpo ?? new \stdClass()]);
        } catch (ConnectionException $e) {
            throw new GatewayIncierto('No se pudo conectar con PayPal: ' . $e->getMessage(), 0, $e);
        }
    }

    private function token(): string
    {
        return Cache::remember($this->claveToken(), now()->addMinutes(5), function () {
            try {
                $resp = Http::asForm()
                    ->withBasicAuth((string) config('services.paypal.client_id'), (string) config('services.paypal.secret'))
                    ->acceptJson()
                    ->timeout(self::TIMEOUT)
                    ->post($this->baseUrl() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            } catch (ConnectionException $e) {
                throw new GatewayIncierto('No se pudo autenticar con PayPal (conexión).', 0, $e);
            }

            if ($resp->serverError()) {
                throw new GatewayIncierto('PayPal no respondió a la autenticación.');
            }

            $token = $resp->json('access_token');
            if ($resp->failed() || !is_string($token) || $token === '') {
                throw new GatewayRechazo('Credenciales de PayPal inválidas.', 'AUTHENTICATION_FAILURE');
            }

            return $token;
        });
    }

    private function claveToken(): string
    {
        return 'finance.paypal.token.' . md5((string) config('services.paypal.client_id') . '|' . config('services.paypal.mode'));
    }
}
