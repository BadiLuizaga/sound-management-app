<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Gateways\GatewayIncierto;
use Modules\Finance\Gateways\GatewayRechazo;
use Modules\Finance\Gateways\PaymentGateway;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Models\WebhookEvento;
use Modules\Finance\Webhooks\PayPalWebhookProcessor;

/**
 * Conciliación periódica (finanzas:conciliar).
 *
 * Expiración: SOLO 'iniciado' y 'orden_creada' vencidos, y una orden creada
 * solo expira después de confirmar con PayPal que no fue aprobada ni pagada.
 * 'verificando' (resultado incierto) NUNCA expira.
 */
class ConciliacionService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PagoService $pagos,
        private readonly ReembolsoService $reembolsos,
    ) {
    }

    /**
     * @return array<string, int> resumen de acciones
     */
    public function ejecutar(): array
    {
        $resumen = [
            'expirados' => 0, 'recibidos' => 0, 'capturados' => 0, 'sin_cambios' => 0,
            'errores_proveedor' => 0, 'reaplicados' => 0, 'reembolsos_resueltos' => 0, 'reembolsos_sin_id' => 0,
            'webhooks_reprocesados' => 0, 'alertas_verificando' => 0,
        ];

        $this->expirar($resumen);
        $this->resolverVerificando($resumen);
        $this->reaplicarPendientes($resumen);
        $this->resolverReembolsos($resumen);
        $this->reprocesarWebhooks($resumen);

        return $resumen;
    }

    private function expirar(array &$resumen): void
    {
        $vencidos = Pago::whereIn('estado', [EstadoPago::Iniciado->value, EstadoPago::OrdenCreada->value])
            ->where('canal', Pago::CANAL_EN_LINEA)
            ->whereNotNull('expira_at')
            ->where('expira_at', '<', now())
            ->orderBy('id')
            ->get();

        foreach ($vencidos as $pago) {
            if ($pago->estado === EstadoPago::Iniciado || $pago->referencia_externa === null) {
                $this->expirarSiSigue($pago, EstadoPago::Iniciado, 'sin orden en el proveedor');
                $resumen['expirados']++;
                continue;
            }

            // orden_creada: primero se pregunta a PayPal qué pasó realmente
            try {
                $orden = $this->gateway->consultarOrden((string) $pago->referencia_externa);
            } catch (GatewayIncierto $e) {
                $resumen['errores_proveedor']++;
                continue; // no se toca: se reintenta en la siguiente ejecución
            } catch (GatewayRechazo $e) {
                if ($e->codigo === 'RESOURCE_NOT_FOUND') {
                    $this->expirarSiSigue($pago, EstadoPago::OrdenCreada, 'la orden no existe en PayPal');
                    $resumen['expirados']++;
                } else {
                    $resumen['errores_proveedor']++;
                }
                continue;
            }

            if ($orden->capturaCompletada() || $orden->estadoOrden === 'COMPLETED') {
                $this->pagos->registrarResultadoProveedor($pago, $orden, 'conciliacion');
                $this->pagos->aplicar($pago->fresh());
                $resumen['recibidos']++;
            } elseif ($orden->estadoOrden === 'APPROVED') {
                // El cliente aprobó pero el navegador no llegó a pedir la captura
                $this->pagos->capturar($pago);
                $resumen['capturados']++;
            } elseif (in_array($orden->estadoOrden, ['CREATED', 'PAYER_ACTION_REQUIRED', 'VOIDED'], true)) {
                $this->expirarSiSigue($pago, EstadoPago::OrdenCreada, "orden {$orden->estadoOrden} sin aprobar");
                $resumen['expirados']++;
            } else {
                $resumen['sin_cambios']++;
            }
        }
    }

    private function expirarSiSigue(Pago $pago, EstadoPago $esperado, string $motivo): void
    {
        DB::transaction(function () use ($pago, $esperado, $motivo) {
            $p = $this->pagos->bloquear($pago->id);
            if ($p->estado === $esperado) {
                $this->pagos->transicionar($p, 'expirado', EstadoPago::Expirado, EstadoAplicacion::NoAplica, [], ['motivo' => $motivo]);
            }
        });
    }

    private function resolverVerificando(array &$resumen): void
    {
        $alertaHoras = (int) config('finance.verificando_alerta_horas', 24);

        foreach (Pago::where('estado', EstadoPago::Verificando->value)->whereNotNull('referencia_externa')->orderBy('id')->get() as $pago) {
            try {
                $despues = $this->pagos->conciliarConProveedor($pago);
            } catch (GatewayIncierto|GatewayRechazo $e) {
                $resumen['errores_proveedor']++;
                $despues = $pago->fresh();
            }

            if ($despues->estado === EstadoPago::Verificando) {
                // Sigue incierto: nunca expira; tras N horas se avisa (sin cambiar el estado del dinero)
                if ($despues->updated_at->lt(now()->subHours($alertaHoras))) {
                    $this->pagos->evento($despues, 'alerta_verificando_prolongado', ['horas' => $alertaHoras]);
                    Log::warning('Pago en verificación prolongada', ['pago' => $despues->uuid]);
                    $resumen['alertas_verificando']++;
                } else {
                    $resumen['sin_cambios']++;
                }
            } else {
                $resumen['recibidos']++;
            }
        }
    }

    private function reaplicarPendientes(array &$resumen): void
    {
        $pendientes = Pago::whereIn('estado', [EstadoPago::Recibido->value])
            ->where('estado_aplicacion', EstadoAplicacion::Pendiente->value)
            ->orderBy('id')->get();

        foreach ($pendientes as $pago) {
            $this->pagos->aplicar($pago);
            $resumen['reaplicados']++;
        }
    }

    private function resolverReembolsos(array &$resumen): void
    {
        $abiertos = Reembolso::with('pago')->whereIn('estado', [EstadoReembolso::Procesando->value, EstadoReembolso::Incierto->value])
            ->whereHas('pago', fn ($q) => $q->where('proveedor', Pago::PROVEEDOR_PAYPAL))
            ->orderBy('id')->get();

        foreach ($abiertos as $r) {
            // Solo se CONSULTA el estado de un reembolso del historial que ya tiene
            // id de PayPal. La aplicación ya no inicia reembolsos: uno sin id se deja
            // como está (lo resolverá el webhook de PayPal si llega a procesarse).
            if ($r->referencia_externa === null) {
                $resumen['reembolsos_sin_id']++;
                continue;
            }
            try {
                $resultado = $this->gateway->consultarReembolso($r->referencia_externa);
            } catch (GatewayIncierto|GatewayRechazo $e) {
                $resumen['errores_proveedor']++;
                continue;
            }

            $this->reembolsos->aplicarResultado($r, $resultado);
            $resumen['reembolsos_resueltos']++;
        }
    }

    private function reprocesarWebhooks(array &$resumen): void
    {
        $pendientes = WebhookEvento::where('firma_valida', true)->whereNull('procesado_at')->where('intentos', '<', 10)->orderBy('id')->get();
        foreach ($pendientes as $evento) {
            app(PayPalWebhookProcessor::class)->procesar($evento);
            $resumen['webhooks_reprocesados']++;
        }
    }
}
