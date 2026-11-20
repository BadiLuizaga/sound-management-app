<?php

namespace Modules\Finance\Gateways\Resultados;

use Modules\Finance\Support\Dinero;

/**
 * Estado de una orden (y su primera captura) tal como lo informa el proveedor.
 */
final class ResultadoOrden
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $estadoOrden,       // CREATED | APPROVED | COMPLETED | VOIDED | ...
        public readonly ?string $customId,
        public readonly ?string $capturaId,
        public readonly ?string $estadoCaptura,    // COMPLETED | PENDING | DECLINED | ...
        public readonly ?Dinero $montoCapturado,
        public readonly array $respuesta = [],
    ) {
    }

    public function capturaCompletada(): bool
    {
        return $this->capturaId !== null && $this->estadoCaptura === 'COMPLETED';
    }
}
