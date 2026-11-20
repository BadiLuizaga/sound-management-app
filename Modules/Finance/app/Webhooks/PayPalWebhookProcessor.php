<?php

namespace Modules\Finance\Webhooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Gateways\Resultados\ResultadoOrden;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Models\WebhookEvento;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Services\ReembolsoService;
use Modules\Finance\Support\Dinero;

/**
 * Procesa eventos de PayPal YA verificados y guardados en webhook_eventos.
 * Idempotente: un evento procesado no se vuelve a aplicar.
 */
class PayPalWebhookProcessor
{
    public function __construct(
        private readonly PagoService $pagos,
        private readonly ReembolsoService $reembolsos,
    ) {
    }

    public function procesar(WebhookEvento $evento): void
    {
        if (!$evento->firma_valida || $evento->procesado_at !== null) {
            return;
        }

        $evento->increment('intentos');

        try {
            [$resultado, $pagoId] = $this->despachar($evento);
            $evento->update(['procesado_at' => now(), 'resultado' => $resultado, 'pago_id' => $pagoId, 'error' => null]);
        } catch (\Throwable $e) {
            Log::error('Error al procesar webhook de PayPal', ['evento' => $evento->evento_id, 'error' => $e->getMessage()]);
            $evento->update(['resultado' => 'error', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private function despachar(WebhookEvento $evento): array
    {
        $recurso = (array) ($evento->payload['resource'] ?? []);

        return match ($evento->tipo) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->capturaCompletada($recurso),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => $this->capturaDenegada($recurso),
            'PAYMENT.CAPTURE.PENDING' => $this->capturaPendiente($recurso),
            'PAYMENT.CAPTURE.REFUNDED' => $this->capturaReembolsada($recurso),
            'PAYMENT.CAPTURE.REVERSED' => $this->capturaRevertida($recurso),
            'CHECKOUT.ORDER.APPROVED' => $this->ordenAprobada($recurso),
            default => ['ignorado', null],
        };
    }

    private function pagoDeCaptura(array $captura): ?Pago
    {
        $orderId = $captura['supplementary_data']['related_ids']['order_id'] ?? null;

        return Pago::where('proveedor', Pago::PROVEEDOR_PAYPAL)
            ->where(function ($q) use ($captura, $orderId) {
                $q->where('referencia_captura', $captura['id'] ?? '__');
                if (!empty($captura['custom_id'])) {
                    $q->orWhere('uuid', $captura['custom_id']);
                }
                if ($orderId) {
                    $q->orWhere('referencia_externa', $orderId);
                }
            })->first();
    }

    private function capturaCompletada(array $captura): array
    {
        $pago = $this->pagoDeCaptura($captura);
        if (!$pago) {
            return ['pago_no_encontrado', null];
        }

        $monto = isset($captura['amount']['value'], $captura['amount']['currency_code'])
            ? Dinero::deDecimal((string) $captura['amount']['value'], (string) $captura['amount']['currency_code'])
            : null;

        $resultado = new ResultadoOrden(
            orderId: (string) ($captura['supplementary_data']['related_ids']['order_id'] ?? $pago->referencia_externa),
            estadoOrden: 'COMPLETED',
            customId: $captura['custom_id'] ?? null,
            capturaId: $captura['id'] ?? null,
            estadoCaptura: 'COMPLETED',
            montoCapturado: $monto,
            respuesta: ['webhook' => 'PAYMENT.CAPTURE.COMPLETED', 'capture_id' => $captura['id'] ?? null, 'amount' => $captura['amount'] ?? null],
        );

        $this->pagos->registrarResultadoProveedor($pago, $resultado, 'webhook');
        $this->pagos->aplicar($pago->fresh());

        return ['procesado', $pago->id];
    }

    private function capturaDenegada(array $captura): array
    {
        $pago = $this->pagoDeCaptura($captura);
        if (!$pago) {
            return ['pago_no_encontrado', null];
        }

        DB::transaction(function () use ($pago) {
            $p = $this->pagos->bloquear($pago->id);
            if ($p->estado->puedePasarA(EstadoPago::Fallido)) {
                $this->pagos->transicionar($p, 'captura_denegada_webhook', EstadoPago::Fallido);
            }
        });

        return ['procesado', $pago->id];
    }

    private function capturaPendiente(array $captura): array
    {
        $pago = $this->pagoDeCaptura($captura);
        if (!$pago) {
            return ['pago_no_encontrado', null];
        }

        DB::transaction(function () use ($pago) {
            $p = $this->pagos->bloquear($pago->id);
            if ($p->estado === EstadoPago::OrdenCreada) {
                $this->pagos->transicionar($p, 'captura_pendiente_webhook', EstadoPago::Verificando);
            }
        });

        return ['procesado', $pago->id];
    }

    private function capturaDesdeEnlaces(array $recurso): ?string
    {
        foreach ((array) ($recurso['links'] ?? []) as $link) {
            if (($link['rel'] ?? null) === 'up' && preg_match('#/captures/([^/]+)$#', (string) ($link['href'] ?? ''), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function capturaReembolsada(array $refund): array
    {
        $capturaId = $this->capturaDesdeEnlaces($refund);
        $pago = $capturaId ? Pago::where('proveedor', Pago::PROVEEDOR_PAYPAL)->where('referencia_captura', $capturaId)->first() : null;
        if (!$pago || empty($refund['id'])) {
            return ['pago_no_encontrado', null];
        }

        $existente = Reembolso::where('referencia_externa', $refund['id'])->first();
        if ($existente) {
            $this->reembolsos->aplicarResultado($existente, new \Modules\Finance\Gateways\Resultados\ResultadoReembolso(
                (string) $refund['id'], (string) ($refund['status'] ?? 'COMPLETED'), null, ['id' => $refund['id'], 'status' => $refund['status'] ?? null]
            ));
            return ['procesado', $pago->id];
        }

        // Reembolso iniciado por la aplicación cuyo id aún no conocíamos (respuesta incierta)
        $enCurso = $pago->reembolsos()->whereNull('referencia_externa')
            ->whereIn('estado', ['procesando', 'incierto'])
            ->where('monto_proveedor', (string) ($refund['amount']['value'] ?? '-1'))
            ->orderBy('id')->first();
        if ($enCurso) {
            $this->reembolsos->aplicarResultado($enCurso, new \Modules\Finance\Gateways\Resultados\ResultadoReembolso(
                (string) $refund['id'], (string) ($refund['status'] ?? 'COMPLETED'), null, ['id' => $refund['id'], 'status' => $refund['status'] ?? null]
            ));
            return ['procesado', $pago->id];
        }

        if (($refund['status'] ?? 'COMPLETED') === 'COMPLETED' && isset($refund['amount']['value'], $refund['amount']['currency_code'])) {
            $this->reembolsos->registrarExterno(
                $pago,
                (string) $refund['id'],
                Dinero::deDecimal((string) $refund['amount']['value'], (string) $refund['amount']['currency_code']),
                ['id' => $refund['id'], 'status' => $refund['status'] ?? null, 'amount' => $refund['amount']]
            );
        }

        return ['procesado', $pago->id];
    }

    private function capturaRevertida(array $recurso): array
    {
        $capturaId = $this->capturaDesdeEnlaces($recurso) ?? ($recurso['id'] ?? null);
        $pago = $capturaId ? Pago::where('proveedor', Pago::PROVEEDOR_PAYPAL)->where('referencia_captura', $capturaId)->first() : null;
        if (!$pago) {
            return ['pago_no_encontrado', null];
        }

        DB::transaction(function () use ($pago) {
            $p = $this->pagos->bloquear($pago->id);
            if ($p->estado->puedePasarA(EstadoPago::Revertido)) {
                $aplicacion = $p->estado_aplicacion->puedePasarA(EstadoAplicacion::RequiereRevision) ? EstadoAplicacion::RequiereRevision : null;
                $this->pagos->transicionar($p, 'revertido_webhook', EstadoPago::Revertido, $aplicacion, [
                    'motivo_revision' => 'PayPal revirtió el cobro (contracargo o reverso).',
                ]);
            }
        });

        return ['procesado', $pago->id];
    }

    private function ordenAprobada(array $orden): array
    {
        $customId = $orden['purchase_units'][0]['custom_id'] ?? null;
        $pago = Pago::where('proveedor', Pago::PROVEEDOR_PAYPAL)
            ->where(fn ($q) => $q->where('referencia_externa', $orden['id'] ?? '__')->orWhere('uuid', $customId ?? '__'))
            ->first();
        if (!$pago) {
            return ['pago_no_encontrado', null];
        }

        if ($pago->estado === EstadoPago::OrdenCreada) {
            // El navegador se cerró antes de pedir la captura: la hace el servidor
            $this->pagos->capturar($pago);
        }

        return ['procesado', $pago->id];
    }
}
