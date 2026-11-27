<?php

namespace Modules\Finance\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Aplicadores\AplicadorReserva;
use Modules\Finance\Exceptions\PagoNoDisponible;
use Modules\Finance\Models\Pago;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Support\EstadoCuentaReserva;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEvento;
use Modules\Sales\Models\MetodoPago;

/**
 * Pagos de reservas (Etapa 4 — paso 4): anticipos y saldos, en caja y en línea.
 *
 * Fachada de los canales (ERP, POS y portal). No duplica lógica: delega en
 *  - PagoService::registrarPresencial  (caja abierta, movimientos y arqueo por caja_id)
 *  - PagoService::iniciarEnLinea / crearOrden / capturar (PayPal, webhooks,
 *    idempotencia y conciliación existentes)
 *  - AplicadorReserva (estado de cuenta, saldo, sobrepago y estado_pago)
 *
 * Reglas:
 *  - Importes en centavos (Dinero), moneda BOB.
 *  - Exigible = total + total_cargos; un cobro nunca supera el saldo disponible.
 *  - Anticipo = deja saldo; saldo = liquida la reserva (snapshot.tipo_cobro).
 *  - Pagar NUNCA confirma la reserva ni bloquea equipos: solo cambia estado_pago.
 *  - Cada operación es idempotente por clave y queda en pago_eventos,
 *    reserva_eventos y la bitácora.
 */
class PagoReservaService
{
    public const CANAL_CAJA = 'caja';
    public const CANAL_EN_LINEA = 'en_linea';

    public function __construct(
        private readonly PagoService $pagos,
        private readonly AplicadorReserva $aplicador,
    ) {
    }

    public function estadoCuenta(Reserva|int $reserva): EstadoCuentaReserva
    {
        $r = $reserva instanceof Reserva ? $reserva->fresh() : Reserva::findOrFail($reserva);

        return $this->aplicador->estadoCuenta($r);
    }

    // ───────────────────────── En caja ─────────────────────────

    /**
     * Cobro presencial (efectivo, tarjeta, transferencia o QR) de un anticipo o
     * del saldo. Exige la caja abierta del cajero. Efectivo y tarjeta se aplican
     * en la misma transacción; transferencia y QR quedan pendientes de
     * verificación y comprometen el saldo hasta que se verifiquen o rechacen.
     *
     * @param array{clave_idempotencia: string, referencia?: ?string, efectivo_recibido?: ?string} $datos
     */
    public function cobrarEnCaja(Usuario $cajero, Reserva|int $reserva, string $codigoMetodo, Dinero $monto, array $datos): Pago
    {
        $reservaId = $reserva instanceof Reserva ? (int) $reserva->id : $reserva;
        $this->validarMonto($monto);
        $clave = $this->clave($datos['clave_idempotencia'] ?? '');
        $claveCompleta = "rsv-{$reservaId}-{$cajero->ci}-{$clave}";

        $pago = DB::transaction(function () use ($cajero, $codigoMetodo, $monto, $datos, $claveCompleta, $reservaId) {
            // PRIMERA instrucción de la transacción: bloquear la reserva (FOR UPDATE).
            // En MySQL (REPEATABLE READ) la instantánea de lectura se fija en la primera
            // lectura NO bloqueante de la transacción; si alguna lectura ocurriera antes
            // del bloqueo (p. ej. MetodoPago::idPorCodigo), los pagos confirmados por un
            // cobro concurrente quedarían fuera de la instantánea y habría sobrepago.
            // Todo cambio que aumenta lo cobrado o comprometido de una reserva toma este
            // mismo bloqueo, así que a partir de aquí las lecturas ven el saldo real.
            $r = $this->aplicador->bloquear($reservaId);
            $cuenta = $this->aplicador->estadoCuenta($r);
            $snapshot = $this->snapshot($r, $cuenta, $monto, self::CANAL_CAJA);

            $pago = $this->pagos->registrarPresencial($cajero, $codigoMetodo, $monto, $snapshot, [
                'referencia' => $datos['referencia'] ?? null,
                'efectivo_recibido' => $datos['efectivo_recibido'] ?? null,
                'clave_idempotencia' => $claveCompleta,
                'reserva_id' => $reservaId,
            ]);

            if ($pago->wasRecentlyCreated) {
                $this->eventoRegistro($pago, $cajero);
            }

            return $pago;
        }, 3);

        $this->exigirMismaReserva($pago, $reservaId);

        return $pago;
    }

    // ───────────────────────── En línea (PayPal) ─────────────────────────

    /**
     * Inicia el pago en línea (PayPal) de un anticipo o del saldo y crea la orden
     * en el servidor. Solo el titular de la reserva. Sin monto, se paga todo el
     * saldo disponible. La captura (navegador, webhook o conciliación) vuelve a
     * comprobar el saldo con la reserva bloqueada ANTES de mover el dinero.
     */
    public function pagarEnLinea(Usuario $cliente, Reserva|int $reserva, ?Dinero $monto, string $claveIdempotencia): Pago
    {
        $r = $reserva instanceof Reserva ? $reserva->fresh() : Reserva::findOrFail($reserva);
        if ((string) $r->ci_cliente !== (string) $cliente->ci) {
            throw new AuthorizationException('Solo el titular de la reserva puede pagarla en línea.');
        }

        $clave = $this->clave($claveIdempotencia);
        $cuenta = $this->aplicador->estadoCuenta($r);
        $monto ??= $cuenta->disponible();
        if (!$monto->esPositivo()) {
            throw new PagoNoDisponible($cuenta->saldo()->esPositivo()
                ? 'La reserva tiene pagos pendientes de confirmación por todo su saldo.'
                : 'La reserva no tiene saldo pendiente.');
        }
        $this->validarMonto($monto);

        $snapshot = $this->snapshot($r, $cuenta, $monto, self::CANAL_EN_LINEA);
        $pago = $this->pagos->iniciarEnLinea($cliente, $monto, $snapshot, "rsv-web-{$r->id}-{$cliente->ci}-{$clave}", (int) $r->id);
        $this->exigirMismaReserva($pago, (int) $r->id);

        if ($pago->wasRecentlyCreated) {
            Bitacora::registrar('RESERVA_PAGO_EN_LINEA', 'reservas', (int) $r->id,
                "Pago en línea {$pago->uuid} de {$monto->decimal()} BOB iniciado para la reserva {$r->codigo} ({$snapshot['tipo_cobro']}).");
        }

        return $this->pagos->crearOrden($pago);
    }

    // ───────────────────────── Internos ─────────────────────────

    private function snapshot(Reserva $r, EstadoCuentaReserva $cuenta, Dinero $monto, string $canal): array
    {
        return [
            'tipo' => PagoService::TIPO_RESERVA,
            'reserva_id' => (int) $r->id,
            'codigo' => (string) $r->codigo,
            'ci_cliente' => (int) $r->ci_cliente,
            'canal' => $canal,
            'tipo_cobro' => $this->aplicador->tipoCobro($cuenta, $monto),
            'exigible' => $cuenta->exigible->decimal(),
            'saldo_antes' => $cuenta->saldo()->decimal(),
            'total' => $monto->decimal(),
        ];
    }

    private function eventoRegistro(Pago $pago, Usuario $cajero): void
    {
        $reserva = Reserva::find($pago->reserva_id);
        $metodo = MetodoPago::whereKey($pago->metodo_pago_id)->value('codigo');

        ReservaEvento::create([
            'reserva_id' => $reserva->id,
            'tipo' => ReservaEvento::PAGO_REGISTRADO,
            'estado_anterior' => $reserva->estado,
            'estado_nuevo' => $reserva->estado,
            'ci_responsable' => $cajero->ci,
            'datos' => [
                'pago' => $pago->uuid,
                'metodo' => $metodo,
                'monto' => (string) $pago->monto,
                'estado' => $pago->estado->value,
                'tipo_cobro' => $pago->snapshot['tipo_cobro'] ?? null,
                'caja_id' => $pago->caja_id,
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
        Bitacora::registrar('RESERVA_PAGO_REGISTRADO', 'reservas', (int) $reserva->id,
            "Cobro en caja {$pago->uuid} de {$pago->monto} BOB ({$metodo}, {$pago->estado->value}) para la reserva {$reserva->codigo}.");
    }

    private function exigirMismaReserva(Pago $pago, int $reservaId): void
    {
        if ((int) $pago->reserva_id !== $reservaId) {
            throw new \DomainException('La clave de la operación ya se usó para otro pago.');
        }
    }

    private function validarMonto(Dinero $monto): void
    {
        if ($monto->moneda !== 'BOB') {
            throw new \DomainException('Los pagos de reservas se registran en BOB.');
        }
        if (!$monto->esPositivo()) {
            throw new \DomainException('El importe debe ser mayor que cero.');
        }
    }

    private function clave(string $clave): string
    {
        $clave = trim($clave);
        if (!preg_match('/^[A-Za-z0-9-]{16,50}$/', $clave)) {
            throw new \DomainException('La clave de idempotencia debe tener entre 16 y 50 caracteres (letras, números o guiones).');
        }

        return $clave;
    }
}
