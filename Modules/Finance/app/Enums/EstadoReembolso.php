<?php

namespace Modules\Finance\Enums;

enum EstadoReembolso: string
{
    case Solicitado = 'solicitado';
    case Autorizado = 'autorizado';
    case Procesando = 'procesando';
    case Completado = 'completado';
    case Fallido = 'fallido';
    case Incierto = 'incierto';
    case Rechazado = 'rechazado';

    /**
     * @return self[]
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Solicitado => [self::Autorizado, self::Rechazado],
            self::Autorizado => [self::Procesando, self::Completado, self::Fallido],
            self::Procesando => [self::Completado, self::Fallido, self::Incierto],
            // Incierto (timeout): nunca se descarta; solo lo resuelve una respuesta del proveedor
            self::Incierto => [self::Completado, self::Fallido],
            self::Completado, self::Fallido, self::Rechazado => [],
        };
    }

    public function puedePasarA(self $nuevo): bool
    {
        return in_array($nuevo, $this->transicionesPermitidas(), true);
    }

    /**
     * Cuenta para el límite "Σ reembolsos ≤ recibido" (completados y en curso).
     */
    public function comprometeImporte(): bool
    {
        return in_array($this, [self::Solicitado, self::Autorizado, self::Procesando, self::Completado, self::Incierto], true);
    }
}
