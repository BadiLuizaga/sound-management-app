<?php

namespace Modules\Finance\Gateways;

use Modules\Finance\Gateways\Resultados\ResultadoOrden;
use Modules\Finance\Gateways\Resultados\ResultadoReembolso;
use Modules\Finance\Models\Pago;

/**
 * Proveedor de pagos en línea.
 *
 * Errores:
 *  - GatewayIncierto: timeout, error de red o 5xx. El resultado es DESCONOCIDO:
 *    nunca se debe concluir que no hubo cobro.
 *  - GatewayRechazo: respuesta definitiva del proveedor (4xx).
 */
interface PaymentGateway
{
    public function configurado(): bool;

    public function moneda(): string;

    /** Crea la orden por `monto_proveedor` con custom_id = uuid del pago. */
    public function crearOrden(Pago $pago): ResultadoOrden;

    /** Captura una orden aprobada (idempotente por $requestId). */
    public function capturarOrden(string $orderId, string $requestId): ResultadoOrden;

    /** Consulta el estado real de una orden (y su captura, si existe). */
    public function consultarOrden(string $orderId): ResultadoOrden;

    /**
     * Consulta el estado de un reembolso ya existente en PayPal (historial).
     * SOUND POS no inicia reembolsos: no hay método para ejecutarlos.
     */
    public function consultarReembolso(string $refundId): ResultadoReembolso;

    /**
     * Verifica la firma de un webhook con el proveedor.
     *
     * @param array<string, string> $cabeceras
     */
    public function verificarFirmaWebhook(array $cabeceras, array $evento): bool;
}
