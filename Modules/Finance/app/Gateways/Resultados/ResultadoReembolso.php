<?php

namespace Modules\Finance\Gateways\Resultados;

use Modules\Finance\Support\Dinero;

final class ResultadoReembolso
{
    public function __construct(
        public readonly string $refundId,
        public readonly string $estado,            // COMPLETED | PENDING | FAILED | CANCELLED
        public readonly ?Dinero $monto,
        public readonly array $respuesta = [],
    ) {
    }
}
