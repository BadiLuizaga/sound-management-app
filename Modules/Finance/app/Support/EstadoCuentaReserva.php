<?php

namespace Modules\Finance\Support;

/**
 * Estado de cuenta de una reserva (Etapa 4, paso 4). Todo en centavos BOB.
 *
 *   exigible      = reservas.total + reservas.total_cargos
 *   cobrado       = Σ pagos con dinero recibido, no revertidos y APLICADOS a la reserva
 *   reembolsado   = Σ reembolsos completados de esos pagos (solo historial: SOUND POS
 *                   no ejecuta reembolsos; los registra si PayPal los notifica)
 *   neto          = cobrado − reembolsado
 *   comprometido  = Σ pagos que todavía pueden convertirse en dinero aplicado:
 *                   transferencias/QR pendientes de verificación, capturas de
 *                   PayPal en curso ('verificando') y dinero recibido aún sin
 *                   aplicar (pendiente o en revisión)
 *   saldo         = max(0, exigible − neto)            → lo que falta cobrar
 *   disponible    = max(0, saldo − comprometido)       → lo que se puede cobrar AHORA
 *   sobrepago     = max(0, neto − exigible)
 */
final class EstadoCuentaReserva
{
    /**
     * @param array<int, array<string, mixed>> $pagos historial de pagos de la reserva
     */
    public function __construct(
        public readonly int $reservaId,
        public readonly string $codigo,
        public readonly string $estadoReserva,
        public readonly string $estadoPago,
        public readonly Dinero $total,
        public readonly Dinero $totalCargos,
        public readonly Dinero $exigible,
        public readonly Dinero $cobrado,
        public readonly Dinero $reembolsado,
        public readonly Dinero $comprometido,
        public readonly array $pagos = [],
    ) {
    }

    public function neto(): Dinero
    {
        return $this->cobrado->restar($this->reembolsado);
    }

    public function saldo(): Dinero
    {
        $saldo = $this->exigible->restar($this->neto());

        return $saldo->centavos > 0 ? $saldo : Dinero::cero('BOB');
    }

    public function disponible(): Dinero
    {
        $disponible = $this->saldo()->restar($this->comprometido);

        return $disponible->centavos > 0 ? $disponible : Dinero::cero('BOB');
    }

    public function sobrepago(): Dinero
    {
        $exceso = $this->neto()->restar($this->exigible);

        return $exceso->centavos > 0 ? $exceso : Dinero::cero('BOB');
    }

    public function toArray(): array
    {
        return [
            'reserva_id' => $this->reservaId,
            'codigo' => $this->codigo,
            'estado' => $this->estadoReserva,
            'estado_pago' => $this->estadoPago,
            'moneda' => 'BOB',
            'total' => $this->total->decimal(),
            'total_cargos' => $this->totalCargos->decimal(),
            'exigible' => $this->exigible->decimal(),
            'cobrado' => $this->cobrado->decimal(),
            'reembolsado' => $this->reembolsado->decimal(),
            'neto' => $this->neto()->decimal(),
            'comprometido' => $this->comprometido->decimal(),
            'saldo' => $this->saldo()->decimal(),
            'disponible' => $this->disponible()->decimal(),
            'sobrepago' => $this->sobrepago()->decimal(),
            'pagos' => $this->pagos,
        ];
    }
}
