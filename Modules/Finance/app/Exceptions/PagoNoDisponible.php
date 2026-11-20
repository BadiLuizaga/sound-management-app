<?php

namespace Modules\Finance\Exceptions;

/**
 * El pago no puede iniciarse (configuración incompleta, sin tasa, método inactivo...).
 * Se lanza ANTES de cobrar: nunca hay dinero involucrado.
 */
class PagoNoDisponible extends \DomainException
{
}
