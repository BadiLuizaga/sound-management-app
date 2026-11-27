<?php

namespace Modules\Finance\Aplicadores;

use Modules\Audit\Models\Bitacora;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Exceptions\AplicacionFallida;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Support\EstadoCuentaReserva;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEvento;

/**
 * Aplica un pago a una reserva: SOLO recalcula `reservas.estado_pago`.
 * Nunca cambia `reservas.estado` (la confirmación es independiente del pago)
 * ni bloquea equipos.
 *
 * Etapa 4 — paso 4 (anticipos y saldos):
 *  - Importe exigible = total + total_cargos (§12.5).
 *  - Solo se cobra a reservas confirmadas, en curso o finalizadas con saldo.
 *  - Sin sobrepago: un cobro nuevo no puede superar el saldo DISPONIBLE
 *    (saldo menos lo comprometido por pagos que aún pueden entrar), y un pago
 *    que al aplicarse dejaría la reserva sobrepagada no se aplica: el dinero
 *    se conserva y el pago queda en revisión (AplicacionFallida).
 *  - Toda comprobación se hace con la fila de la reserva bloqueada (FOR UPDATE).
 *    Orden de bloqueo en todos los flujos: primero el pago, después la reserva
 *    (en el alta de un cobro nuevo, la reserva antes de insertar el pago).
 */
class AplicadorReserva implements AplicadorPago
{
    /** Estados de la reserva que admiten cobros. */
    public const ESTADOS_COBRABLES = [Reserva::ESTADO_CONFIRMADA, Reserva::ESTADO_EN_CURSO, Reserva::ESTADO_FINALIZADA];

    public const TIPO_ANTICIPO = 'anticipo';
    public const TIPO_SALDO = 'saldo';

    public function aplicar(Pago $pago): array
    {
        if ($pago->reserva_id === null) {
            throw new AplicacionFallida('El pago no está asociado a ninguna reserva.');
        }
        $destino = $pago->snapshot['reserva_id'] ?? null;
        if ($destino !== null && (int) $destino !== (int) $pago->reserva_id) {
            throw new AplicacionFallida('El pago no corresponde a la reserva indicada en su registro.');
        }

        $reserva = $this->bloquear((int) $pago->reserva_id);
        $this->exigirEstadoCobrable($reserva);

        if ($pago->moneda !== 'BOB' || ($reserva->moneda ?? 'BOB') !== 'BOB') {
            throw new AplicacionFallida('La moneda del pago o de la reserva no es BOB.');
        }

        // Sin sobrepago: lo ya aplicado por OTROS pagos más este no puede superar lo exigible
        $cuenta = $this->estadoCuenta($reserva, null, (int) $pago->id);
        $importe = $pago->importe();
        if ($cuenta->neto()->sumar($importe)->mayorQue($cuenta->exigible)) {
            throw new AplicacionFallida(sprintf(
                'Sobrepago: el pago de %s BOB supera el saldo de la reserva %s (%s BOB). El dinero se conserva para revisión.',
                $importe->decimal(), $reserva->codigo, $cuenta->saldo()->decimal()
            ));
        }

        [$anterior, $nuevo] = $this->recalcular((int) $reserva->id, $pago);
        $despues = $this->estadoCuenta($reserva->refresh());

        $this->evento($reserva, ReservaEvento::PAGO_APLICADO, $pago->ci_verificado_por ?? $pago->ci_registrado_por, [
            'pago' => $pago->uuid,
            'canal' => $pago->canal,
            'metodo_pago_id' => $pago->metodo_pago_id,
            'monto' => $importe->decimal(),
            'tipo_cobro' => $pago->snapshot['tipo_cobro'] ?? null,
            'estado_pago_anterior' => $anterior,
            'estado_pago_nuevo' => $nuevo,
            'saldo' => $despues->saldo()->decimal(),
        ]);
        Bitacora::registrar('RESERVA_PAGO_APLICADO', 'reservas', (int) $reserva->id,
            "Pago {$pago->uuid} de {$importe->decimal()} BOB aplicado a la reserva {$reserva->codigo} ({$anterior} → {$nuevo}; saldo {$despues->saldo()->decimal()} BOB).");

        return [];
    }

    // ───────────────────────── Estado de cuenta ─────────────────────────

    public function bloquear(int $reservaId): Reserva
    {
        $reserva = Reserva::whereKey($reservaId)->lockForUpdate()->first();
        if (!$reserva) {
            throw new AplicacionFallida("La reserva {$reservaId} no existe.");
        }

        return $reserva;
    }

    /**
     * @param Pago|null $enAplicacion pago que se está aplicando en esta transacción
     *                                (todavía figura como 'pendiente'): cuenta como cobrado
     * @param int|null  $excluirPagoId pago que se ignora por completo (el que se valida)
     */
    public function estadoCuenta(Reserva $reserva, ?Pago $enAplicacion = null, ?int $excluirPagoId = null): EstadoCuentaReserva
    {
        $pagos = Pago::with('metodoPago')->where('reserva_id', $reserva->id)->orderBy('id')->get();

        $reembolsosPorPago = [];
        foreach (Reembolso::whereIn('pago_id', $pagos->pluck('id'))->where('estado', EstadoReembolso::Completado->value)->get() as $r) {
            $reembolsosPorPago[$r->pago_id] = ($reembolsosPorPago[$r->pago_id] ?? Dinero::cero('BOB'))->sumar($r->importe());
        }

        $cobrado = Dinero::cero('BOB');
        $reembolsado = Dinero::cero('BOB');
        $comprometido = Dinero::cero('BOB');
        $historial = [];

        foreach ($pagos as $p) {
            $situacion = 'sin_efecto';
            if ($excluirPagoId === null || (int) $p->id !== $excluirPagoId) {
                if ($this->cuentaComoCobrado($p, $enAplicacion)) {
                    $situacion = 'aplicado';
                    $cobrado = $cobrado->sumar($p->importe());
                    if (isset($reembolsosPorPago[$p->id])) {
                        $reembolsado = $reembolsado->sumar($reembolsosPorPago[$p->id]);
                    }
                } elseif ($this->comprometido($p)) {
                    $situacion = 'comprometido';
                    $comprometido = $comprometido->sumar($p->importe());
                }
            }

            $historial[] = [
                'uuid' => $p->uuid,
                'canal' => $p->canal,
                'metodo' => $p->metodoPago?->codigo,
                'monto' => $p->importe()->decimal(),
                'estado' => $p->estado->value,
                'estado_aplicacion' => $p->estado_aplicacion->value,
                'tipo_cobro' => $p->snapshot['tipo_cobro'] ?? null,
                'situacion' => $situacion,
                'registrado_at' => $p->created_at?->toDateTimeString(),
            ];
        }

        $total = Dinero::deDecimal((string) $reserva->total, 'BOB');
        $cargos = Dinero::deDecimal((string) ($reserva->total_cargos ?? '0'), 'BOB');

        return new EstadoCuentaReserva(
            reservaId: (int) $reserva->id,
            codigo: (string) $reserva->codigo,
            estadoReserva: (string) $reserva->estado,
            estadoPago: (string) $reserva->estado_pago,
            total: $total,
            totalCargos: $cargos,
            exigible: $total->sumar($cargos),
            cobrado: $cobrado,
            reembolsado: $reembolsado,
            comprometido: $comprometido,
            pagos: $historial,
        );
    }

    /**
     * Bloquea la reserva y comprueba que admite un cobro nuevo de $monto:
     * estado cobrable y monto ≤ saldo disponible. Debe llamarse dentro de una
     * transacción, ANTES de registrar el pago (o de capturarlo en PayPal).
     *
     * @throws AplicacionFallida
     */
    public function exigirCobrable(int $reservaId, Dinero $monto, ?int $excluirPagoId = null): Reserva
    {
        $reserva = $this->bloquear($reservaId);
        $this->exigirEstadoCobrable($reserva);

        if ($monto->moneda !== 'BOB' || ($reserva->moneda ?? 'BOB') !== 'BOB') {
            throw new AplicacionFallida('Los cobros de reservas se registran en BOB.');
        }
        if (!$monto->esPositivo()) {
            throw new AplicacionFallida('El importe debe ser mayor que cero.');
        }

        $cuenta = $this->estadoCuenta($reserva, null, $excluirPagoId);
        if ($cuenta->saldo()->centavos === 0) {
            throw new AplicacionFallida("La reserva {$reserva->codigo} ya está pagada: no tiene saldo pendiente.");
        }
        if ($monto->mayorQue($cuenta->disponible())) {
            $detalle = $cuenta->comprometido->esPositivo()
                ? " (saldo {$cuenta->saldo()->decimal()} BOB, de los que {$cuenta->comprometido->decimal()} BOB están en pagos pendientes de verificación o confirmación)"
                : '';
            throw new AplicacionFallida(sprintf(
                'El importe de %s BOB excede el saldo disponible de la reserva %s: %s BOB%s.',
                $monto->decimal(), $reserva->codigo, $cuenta->disponible()->decimal(), $detalle
            ));
        }

        return $reserva;
    }

    /** 'saldo' si el monto liquida la reserva; 'anticipo' si deja saldo pendiente. */
    public function tipoCobro(EstadoCuentaReserva $cuenta, Dinero $monto): string
    {
        return $monto->centavos >= $cuenta->saldo()->centavos ? self::TIPO_SALDO : self::TIPO_ANTICIPO;
    }

    // ───────────────────────── Estado de pago ─────────────────────────

    /**
     * Recalcula `reservas.estado_pago` con la reserva bloqueada.
     *
     * @param Pago|null $enAplicacion pago que se está aplicando en esta transacción
     *                                (todavía figura como 'pendiente')
     * @return array{0: string, 1: string} estado de pago anterior y nuevo
     */
    public function recalcular(int $reservaId, ?Pago $enAplicacion = null): array
    {
        $reserva = $this->bloquear($reservaId);
        $anterior = (string) $reserva->estado_pago;

        $cuenta = $this->estadoCuenta($reserva, $enAplicacion);
        $neto = $cuenta->neto();

        $estadoPago = match (true) {
            $neto->centavos <= 0 && $cuenta->reembolsado->esPositivo() => Reserva::PAGO_REEMBOLSADO,
            $neto->centavos <= 0 => Reserva::PAGO_PENDIENTE,
            $neto->centavos >= $cuenta->exigible->centavos => Reserva::PAGO_PAGADO,
            default => Reserva::PAGO_PARCIAL,
        };

        // Solo el estado de pago; `estado` de la reserva no se toca
        $reserva->forceFill(['estado_pago' => $estadoPago])->save();

        return [$anterior, $estadoPago];
    }

    /**
     * Recalcula tras un hecho que no pasa por aplicar() (cierre manual, reverso
     * notificado por PayPal) y lo deja en el historial de la reserva si cambió.
     */
    public function sincronizar(int $reservaId, string $motivo, ?Pago $pago = null): void
    {
        [$anterior, $nuevo] = $this->recalcular($reservaId);
        if ($anterior === $nuevo) {
            return;
        }

        $reserva = Reserva::find($reservaId);
        $this->evento($reserva, ReservaEvento::ESTADO_PAGO_ACTUALIZADO, null, [
            'motivo' => $motivo,
            'pago' => $pago?->uuid,
            'estado_pago_anterior' => $anterior,
            'estado_pago_nuevo' => $nuevo,
        ]);
        Bitacora::registrar('RESERVA_ESTADO_PAGO', 'reservas', $reservaId, "Reserva {$reserva->codigo}: estado de pago {$anterior} → {$nuevo} ({$motivo}).");
    }

    // ───────────────────────── Internos ─────────────────────────

    private function cuentaComoCobrado(Pago $p, ?Pago $enAplicacion): bool
    {
        return $p->estado->dineroRecibido()
            && $p->estado !== EstadoPago::Revertido
            && ($p->estado_aplicacion->aplicado() || ($enAplicacion && (int) $p->id === (int) $enAplicacion->id));
    }

    /** Pagos que todavía pueden convertirse en dinero aplicado a la reserva. */
    private function comprometido(Pago $p): bool
    {
        return match ($p->estado) {
            EstadoPago::PendienteVerificacion, EstadoPago::Verificando => true,
            EstadoPago::Recibido => in_array($p->estado_aplicacion, [EstadoAplicacion::Pendiente, EstadoAplicacion::RequiereRevision], true),
            default => false,
        };
    }

    private function exigirEstadoCobrable(Reserva $reserva): void
    {
        if (!in_array($reserva->estado, self::ESTADOS_COBRABLES, true)) {
            throw new AplicacionFallida("La reserva {$reserva->codigo} está «{$reserva->estado}»: no admite cobros.");
        }
    }

    private function evento(Reserva $reserva, string $tipo, int|string|null $ciPorDefecto, array $datos): void
    {
        ReservaEvento::create([
            'reserva_id' => $reserva->id,
            'tipo' => $tipo,
            'estado_anterior' => $reserva->estado,
            'estado_nuevo' => $reserva->estado, // el pago nunca cambia el estado de la reserva
            'ci_responsable' => auth()->user()?->ci ?? $ciPorDefecto,
            'datos' => $datos,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
