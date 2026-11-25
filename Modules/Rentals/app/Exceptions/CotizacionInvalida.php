<?php

namespace Modules\Rentals\Exceptions;

/**
 * Solicitud de reserva que no cumple las reglas de cotización: solo paquetes,
 * solo horas completas, horas mínimas y máximas del paquete, paquete activo y
 * con equipos, moneda BOB. El mensaje se puede mostrar al usuario.
 */
class CotizacionInvalida extends \DomainException
{
}
