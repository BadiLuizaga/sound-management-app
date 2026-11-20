<?php

namespace Modules\Finance\Aplicadores;

use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Exceptions\AplicacionFallida;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Support\Dinero;
use Modules\Rentals\Models\Reserva;

/**
 * Aplica un pago a una reserva: SOLO recalcula `reservas.estado_pago`.
 * Nunca cambia `reservas.estado` (la confirmación es independiente del pago)
 * ni bloquea equipos.
 */
class AplicadorReserva implements AplicadorPago
{
    public function aplicar(Pago $pago): array
    {
        if ($pago->reserva_id === null) {
            throw new AplicacionFallida('El pago no está asociado a ninguna reserva.');
        }

        $this->recalcular((int) $pago->reserva_id, $pago);

        return [];
    }

    /**
     * @param Pago|null $enAplicacion pago que se está aplicando en esta transacción
     *                                (todavía figura como 'pendiente')
     */
    public function recalcular(int $reservaId, ?Pago $enAplicacion = null): void
    {
        $reserva = Reserva::whereKey($reservaId)->lockForUpdate()->first();
        if (!$reserva) {
            throw new AplicacionFallida("La reserva {$reservaId} no existe.");
        }

        $pagos = Pago::where('reserva_id', $reservaId)->get();

        $cobrado = Dinero::cero('BOB');
        foreach ($pagos as $p) {
            $cuenta = $p->estado->dineroRecibido()
                && $p->estado !== EstadoPago::Revertido
                && ($p->estado_aplicacion->aplicado() || ($enAplicacion && $p->id === $enAplicacion->id));

            if ($cuenta) {
                $cobrado = $cobrado->sumar($p->importe());
            }
        }

        $reembolsado = Dinero::cero('BOB');
        foreach (Reembolso::whereIn('pago_id', $pagos->pluck('id'))->where('estado', EstadoReembolso::Completado->value)->get() as $r) {
            $reembolsado = $reembolsado->sumar($r->importe());
        }

        $neto = $cobrado->restar($reembolsado);
        $total = Dinero::deDecimal((string) $reserva->total, 'BOB');

        $estadoPago = match (true) {
            $neto->centavos <= 0 && $reembolsado->esPositivo() => Reserva::PAGO_REEMBOLSADO,
            $neto->centavos <= 0 => Reserva::PAGO_PENDIENTE,
            $neto->centavos >= $total->centavos => Reserva::PAGO_PAGADO,
            default => Reserva::PAGO_PARCIAL,
        };

        // Solo el estado de pago; `estado` de la reserva no se toca
        $reserva->forceFill(['estado_pago' => $estadoPago])->save();
    }
}
