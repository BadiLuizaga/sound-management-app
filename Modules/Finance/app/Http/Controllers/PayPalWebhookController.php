<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Gateways\GatewayIncierto;
use Modules\Finance\Gateways\GatewayRechazo;
use Modules\Finance\Gateways\PaymentGateway;
use Modules\Finance\Models\WebhookEvento;
use Modules\Finance\Webhooks\PayPalWebhookProcessor;

/**
 * Webhook de PayPal: público, sin sesión ni CSRF, con firma verificada.
 *  - Firma inválida → 400 (se registra, no se procesa).
 *  - No se pudo verificar (PayPal caído) → 503: PayPal reintentará.
 *  - Válido → se guarda (idempotente por evento_id) y responde 200 aunque el
 *    procesamiento falle; la conciliación lo reintenta.
 */
class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, PayPalWebhookProcessor $procesador)
    {
        $evento = json_decode($request->getContent(), true);
        if (!is_array($evento) || empty($evento['id']) || empty($evento['event_type'])) {
            return response()->json(['message' => 'Evento inválido.'], 400);
        }

        $cabeceras = collect($request->headers->all())
            ->filter(fn ($v, $k) => str_starts_with(strtolower($k), 'paypal-'))
            ->map(fn ($v) => is_array($v) ? (string) ($v[0] ?? '') : (string) $v)
            ->all();

        try {
            $valida = $gateway->configurado() && $gateway->verificarFirmaWebhook($cabeceras, $evento);
        } catch (GatewayIncierto $e) {
            Log::warning('No se pudo verificar la firma de un webhook de PayPal', ['evento' => $evento['id']]);
            return response()->json(['message' => 'Verificación no disponible.'], 503);
        } catch (GatewayRechazo $e) {
            $valida = false;
        }

        $existente = WebhookEvento::where('proveedor', 'paypal')->where('evento_id', (string) $evento['id'])->first();

        if (!$valida) {
            if (!$existente) {
                $this->guardar($evento, $cabeceras, false, 'firma_invalida');
            }
            Log::warning('Webhook de PayPal con firma inválida', ['evento' => $evento['id']]);
            return response()->json(['message' => 'Firma inválida.'], 400);
        }

        if ($existente) {
            if (!$existente->firma_valida) {
                // Un registro previo sin firma válida no impide procesar el evento auténtico
                $existente->update(['firma_valida' => true, 'payload' => $evento, 'cabeceras' => $cabeceras, 'tipo' => (string) $evento['event_type'], 'resultado' => null]);
            } elseif ($existente->procesado_at !== null) {
                return response()->json(['message' => 'Evento ya procesado.']);
            }
            $registro = $existente->fresh();
        } else {
            $registro = $this->guardar($evento, $cabeceras, true, null);
        }

        $procesador->procesar($registro);

        return response()->json(['message' => 'Recibido.']);
    }

    private function guardar(array $evento, array $cabeceras, bool $valida, ?string $resultado): WebhookEvento
    {
        try {
            return WebhookEvento::create([
                'proveedor' => 'paypal',
                'evento_id' => (string) $evento['id'],
                'tipo' => (string) $evento['event_type'],
                'firma_valida' => $valida,
                'payload' => $evento,
                'cabeceras' => $cabeceras,
                'resultado' => $resultado,
            ]);
        } catch (QueryException $e) {
            // Carrera con otra entrega del mismo evento
            return WebhookEvento::where('proveedor', 'paypal')->where('evento_id', (string) $evento['id'])->firstOrFail();
        }
    }
}
