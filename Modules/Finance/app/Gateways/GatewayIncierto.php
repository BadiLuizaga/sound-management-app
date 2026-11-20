<?php

namespace Modules\Finance\Gateways;

/**
 * Resultado desconocido (timeout, red, 5xx). NO significa que no hubo cobro.
 */
class GatewayIncierto extends \RuntimeException
{
}
