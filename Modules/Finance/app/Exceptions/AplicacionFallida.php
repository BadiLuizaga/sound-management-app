<?php

namespace Modules\Finance\Exceptions;

/**
 * La venta o reserva no pudo generarse por una regla de negocio (p. ej. sin
 * stock). El pago recibido se conserva y queda en revisión.
 */
class AplicacionFallida extends \DomainException
{
}
