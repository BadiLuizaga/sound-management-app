<?php

namespace Modules\Finance\Enums;

/**
 * Estado del DINERO de un pago (independiente de su aplicación a una venta/reserva).
 */
enum EstadoPago: string
{
    case Iniciado = 'iniciado';
    case OrdenCreada = 'orden_creada';
    case Verificando = 'verificando';
    case PendienteVerificacion = 'pendiente_verificacion';
    case Recibido = 'recibido';
    case Fallido = 'fallido';
    case Expirado = 'expirado';
    case Cancelado = 'cancelado';
    case Rechazado = 'rechazado';
    case ReembolsadoParcial = 'reembolsado_parcial';
    case Reembolsado = 'reembolsado';
    case Revertido = 'revertido';

    /**
     * @return self[]
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Iniciado => [self::OrdenCreada, self::Fallido, self::Expirado, self::Cancelado],
            self::OrdenCreada => [self::Verificando, self::Recibido, self::Fallido, self::Expirado, self::Cancelado],
            // Resultado incierto: solo lo resuelve una respuesta verificada del proveedor
            self::Verificando => [self::Recibido, self::Fallido],
            self::PendienteVerificacion => [self::Recibido, self::Rechazado],
            self::Recibido => [self::ReembolsadoParcial, self::Reembolsado, self::Revertido],
            self::ReembolsadoParcial => [self::ReembolsadoParcial, self::Reembolsado, self::Revertido],
            self::Fallido, self::Expirado, self::Cancelado, self::Rechazado,
            self::Reembolsado, self::Revertido => [],
        };
    }

    public function puedePasarA(self $nuevo): bool
    {
        return in_array($nuevo, $this->transicionesPermitidas(), true);
    }

    /**
     * El dinero fue efectivamente recibido (aunque luego se haya reembolsado en parte o del todo).
     */
    public function dineroRecibido(): bool
    {
        return in_array($this, [self::Recibido, self::ReembolsadoParcial, self::Reembolsado, self::Revertido], true);
    }

    /**
     * Estados que la expiración automática puede tocar. `verificando` nunca.
     */
    public function expirable(): bool
    {
        return in_array($this, [self::Iniciado, self::OrdenCreada], true);
    }
}
