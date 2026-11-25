<?php

namespace Modules\Rentals\Enums;

/**
 * Ciclo de vida de una reserva (columna `reservas.estado`, sin cambiar su enum).
 *
 *   pendiente ─► confirmada ─► en_curso ─► finalizada
 *       │            └──────► cancelada
 *       ├─► rechazada (sin disponibilidad, D2)
 *       ├─► cancelada
 *       └─► expirada (vencida sin confirmar)
 *
 * El pago es independiente: ninguna transición depende de `estado_pago`.
 */
enum EstadoReserva: string
{
    case Pendiente = 'pendiente';
    case Confirmada = 'confirmada';
    case EnCurso = 'en_curso';
    case Finalizada = 'finalizada';
    case Cancelada = 'cancelada';
    case Expirada = 'expirada';
    case Rechazada = 'rechazada';

    /** @return array<int, self> */
    public function siguientes(): array
    {
        return match ($this) {
            self::Pendiente => [self::Confirmada, self::Rechazada, self::Cancelada, self::Expirada],
            self::Confirmada => [self::EnCurso, self::Cancelada],
            self::EnCurso => [self::Finalizada],
            self::Finalizada, self::Cancelada, self::Expirada, self::Rechazada => [],
        };
    }

    public function puedePasarA(self $destino): bool
    {
        return in_array($destino, $this->siguientes(), true);
    }

    public function esFinal(): bool
    {
        return $this->siguientes() === [];
    }

    /** Estados en los que la reserva tiene (o puede tener) unidades bloqueadas. */
    public function ocupaEquipos(): bool
    {
        return $this === self::Confirmada || $this === self::EnCurso;
    }
}
