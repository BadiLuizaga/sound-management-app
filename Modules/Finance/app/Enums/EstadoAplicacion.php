<?php

namespace Modules\Finance\Enums;

/**
 * Estado de la APLICACIÓN de un pago a su destino (venta o reserva).
 */
enum EstadoAplicacion: string
{
    case Pendiente = 'pendiente';
    case Aplicado = 'aplicado';
    case RequiereRevision = 'requiere_revision';
    case AplicadoManual = 'aplicado_manual';
    case NoAplica = 'no_aplica';

    /**
     * @return self[]
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Pendiente => [self::Aplicado, self::RequiereRevision, self::NoAplica],
            self::RequiereRevision => [self::Aplicado, self::AplicadoManual, self::NoAplica],
            self::Aplicado, self::AplicadoManual, self::NoAplica => [],
        };
    }

    public function puedePasarA(self $nuevo): bool
    {
        return in_array($nuevo, $this->transicionesPermitidas(), true);
    }

    public function aplicado(): bool
    {
        return in_array($this, [self::Aplicado, self::AplicadoManual], true);
    }
}
