<?php

namespace Modules\Rentals\Exceptions;

/**
 * Cambio de estado no permitido por el ciclo de la reserva (EstadoReserva).
 */
class TransicionReservaInvalida extends \DomainException
{
}
