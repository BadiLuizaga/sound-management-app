<?php

namespace Modules\Finance\Gateways;

/**
 * Respuesta definitiva del proveedor que rechaza la operación (4xx).
 */
class GatewayRechazo extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $codigo = null, public readonly array $respuesta = [])
    {
        parent::__construct($message);
    }
}
