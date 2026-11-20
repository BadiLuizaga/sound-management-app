<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Aplicadores\AplicadorReserva;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Gateways\GatewayIncierto;
use Modules\Finance\Gateways\GatewayRechazo;
use Modules\Finance\Gateways\PaymentGateway;
use Modules\Finance\Gateways\Resultados\ResultadoReembolso;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Support\Dinero;

/**
 * Reembolsos totales y parciales.
 *  - Doble paso: el encargado SOLICITA; el administrador AUTORIZA y EJECUTA
 *    (el administrador puede hacer ambos).
 *  - Idempotentes (clave propia + PayPal-Request-Id = "reembolso-{uuid}").
 *  - Σ reembolsos (en curso + completados) ≤ importe recibido, comprobado con
 *    el pago bloqueado.
 *  - El importe en USD usa SIEMPRE la tasa original del pago.
 */
class ReembolsoService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PagoService $pagos,
    ) {
    }

    public function disponible(Pago $pago): Dinero
    {
        $comprometido = Dinero::cero('BOB');
        foreach ($pago->reembolsos()->get() as $r) {
            if ($r->estado->comprometeImporte()) {
                $comprometido = $comprometido->sumar($r->importe());
            }
        }

        $disponible = $pago->importe()->restar($comprometido);

        return $disponible->centavos > 0 ? $disponible : Dinero::cero('BOB');
    }

    public function solicitar(Pago $pago, Dinero $monto, string $motivo, Usuario $solicitante, string $claveIdempotencia, ?int $devolucionId = null): Reembolso
    {
        if (trim($motivo) === '') {
            throw new \DomainException('El motivo del reembolso es obligatorio.');
        }
        if ($monto->moneda !== 'BOB' || !$monto->esPositivo()) {
            throw new \DomainException('El importe a reembolsar debe ser mayor que cero (BOB).');
        }

        return DB::transaction(function () use ($pago, $monto, $motivo, $solicitante, $claveIdempotencia, $devolucionId) {
            $existente = Reembolso::where('clave_idempotencia', $claveIdempotencia)->lockForUpdate()->first();
            if ($existente) {
                if ($existente->pago_id !== $pago->id) {
                    throw new \DomainException('La clave de idempotencia ya está en uso.');
                }
                return $existente;
            }

            $p = $this->pagos->bloquear($pago->id);

            if (!in_array($p->estado, [EstadoPago::Recibido, EstadoPago::ReembolsadoParcial], true)) {
                throw new TransicionInvalida('Solo se pueden reembolsar pagos recibidos.');
            }

            $disponible = $this->disponible($p);
            if ($monto->mayorQue($disponible)) {
                throw new \DomainException("El reembolso supera el importe disponible ({$disponible->decimal()} BOB).");
            }

            $montoProveedor = $p->proveedor === Pago::PROVEEDOR_PAYPAL ? $this->importeProveedor($p, $monto, $disponible) : null;

            $reembolso = Reembolso::create([
                'uuid' => (string) Str::uuid(),
                'clave_idempotencia' => $claveIdempotencia,
                'pago_id' => $p->id,
                'devolucion_id' => $devolucionId,
                'estado' => EstadoReembolso::Solicitado,
                'origen' => Reembolso::ORIGEN_APLICACION,
                'monto' => $monto->decimal(),
                'moneda' => 'BOB',
                'monto_proveedor' => $montoProveedor?->decimal(),
                'moneda_proveedor' => $montoProveedor?->moneda,
                'tasa_aplicada' => $p->tasa_aplicada,
                'motivo' => $motivo,
                'ci_solicitado_por' => $solicitante->ci,
                'solicitado_at' => now(),
            ]);

            $this->pagos->evento($p, 'reembolso_solicitado', [
                'reembolso' => $reembolso->uuid, 'monto' => $monto->decimal(), 'motivo' => $motivo,
            ], $solicitante);

            return $reembolso;
        });
    }

    /**
     * Autoriza y ejecuta. PayPal: llamada a la API fuera de la transacción.
     * Presencial: queda 'autorizado' hasta confirmar la entrega del dinero.
     */
    public function autorizarYEjecutar(Reembolso $reembolso, Usuario $admin): Reembolso
    {
        $r = DB::transaction(function () use ($reembolso, $admin) {
            $r = Reembolso::whereKey($reembolso->id)->lockForUpdate()->firstOrFail();
            if ($r->estado !== EstadoReembolso::Solicitado) {
                return $r; // idempotente: ya autorizado o procesado
            }
            $this->cambiarEstado($r, EstadoReembolso::Autorizado, ['ci_autorizado_por' => $admin->ci, 'autorizado_at' => now()], $admin);

            if ($r->pago->proveedor === Pago::PROVEEDOR_PAYPAL) {
                $this->cambiarEstado($r, EstadoReembolso::Procesando, [], $admin);
            }

            return $r;
        });

        if ($r->estado !== EstadoReembolso::Procesando || $r->referencia_externa !== null) {
            return $r;
        }

        $pago = $r->pago;
        try {
            $resultado = $this->gateway->reembolsarCaptura(
                (string) $pago->referencia_captura,
                Dinero::deDecimal((string) $r->monto_proveedor, (string) $r->moneda_proveedor),
                'reembolso-' . $r->uuid,
                $r->motivo,
            );
        } catch (GatewayIncierto $e) {
            return $this->resolver($r, EstadoReembolso::Incierto, [], ['error' => $e->getMessage()], $admin);
        } catch (GatewayRechazo $e) {
            return $this->resolver($r, EstadoReembolso::Fallido, [], ['codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $admin);
        }

        return $this->aplicarResultado($r, $resultado, $admin);
    }

    /**
     * Reembolso presencial: el personal confirma que devolvió el dinero.
     */
    public function confirmarEntrega(Reembolso $reembolso, Usuario $usuario): Reembolso
    {
        return DB::transaction(function () use ($reembolso, $usuario) {
            $r = Reembolso::whereKey($reembolso->id)->lockForUpdate()->firstOrFail();
            if ($r->pago->proveedor !== Pago::PROVEEDOR_MANUAL) {
                throw new TransicionInvalida('Los reembolsos de PayPal se confirman con la respuesta del proveedor.');
            }
            if ($r->estado === EstadoReembolso::Completado) {
                return $r;
            }
            if ($r->estado !== EstadoReembolso::Autorizado) {
                throw new TransicionInvalida('El reembolso debe estar autorizado.');
            }

            $this->cambiarEstado($r, EstadoReembolso::Completado, ['completado_at' => now()], $usuario, ['entrega_confirmada_por' => $usuario->ci]);
            $this->actualizarPago($r->pago, $usuario);

            return $r;
        });
    }

    public function rechazar(Reembolso $reembolso, Usuario $admin, string $motivo): Reembolso
    {
        return DB::transaction(function () use ($reembolso, $admin, $motivo) {
            $r = Reembolso::whereKey($reembolso->id)->lockForUpdate()->firstOrFail();
            if ($r->estado !== EstadoReembolso::Solicitado) {
                throw new TransicionInvalida('Solo se rechazan reembolsos solicitados.');
            }
            $this->cambiarEstado($r, EstadoReembolso::Rechazado, ['ci_autorizado_por' => $admin->ci, 'autorizado_at' => now()], $admin, ['motivo_rechazo' => $motivo]);

            return $r;
        });
    }

    /**
     * Resultado del proveedor (respuesta directa, conciliación o webhook).
     */
    public function aplicarResultado(Reembolso $reembolso, ResultadoReembolso $resultado, ?Usuario $usuario = null): Reembolso
    {
        $estado = match ($resultado->estado) {
            'COMPLETED' => EstadoReembolso::Completado,
            'PENDING' => EstadoReembolso::Procesando,
            'FAILED', 'CANCELLED' => EstadoReembolso::Fallido,
            default => EstadoReembolso::Incierto,
        };

        return $this->resolver($reembolso, $estado, [
            'referencia_externa' => $resultado->refundId,
            'respuesta_proveedor' => $resultado->respuesta,
        ], ['refund_id' => $resultado->refundId, 'estado_proveedor' => $resultado->estado], $usuario);
    }

    /**
     * Reembolso hecho fuera de la aplicación (panel de PayPal) recibido por webhook.
     */
    public function registrarExterno(Pago $pago, string $refundId, Dinero $montoProveedor, array $respuesta): Reembolso
    {
        return DB::transaction(function () use ($pago, $refundId, $montoProveedor, $respuesta) {
            $existente = Reembolso::where('referencia_externa', $refundId)->first();
            if ($existente) {
                return $existente;
            }

            $p = $this->pagos->bloquear($pago->id);
            $tasa = $p->tasa_aplicada !== null ? (string) $p->tasa_aplicada : null;
            $bob = $tasa ? $montoProveedor->convertirMultiplicando($tasa, 'BOB') : Dinero::deCentavos($montoProveedor->centavos, 'BOB');
            $disponible = $this->disponible($p);
            if ($bob->mayorQue($disponible)) {
                $bob = $disponible->esPositivo() ? $disponible : $bob;
            }

            $r = Reembolso::create([
                'uuid' => (string) Str::uuid(),
                'clave_idempotencia' => 'externo-' . $refundId,
                'pago_id' => $p->id,
                'estado' => EstadoReembolso::Completado,
                'origen' => Reembolso::ORIGEN_EXTERNO,
                'monto' => $bob->decimal(),
                'moneda' => 'BOB',
                'monto_proveedor' => $montoProveedor->decimal(),
                'moneda_proveedor' => $montoProveedor->moneda,
                'tasa_aplicada' => $p->tasa_aplicada,
                'referencia_externa' => $refundId,
                'motivo' => 'Reembolso realizado fuera de la aplicación (notificado por PayPal).',
                'respuesta_proveedor' => $respuesta,
                'completado_at' => now(),
            ]);

            $this->pagos->evento($p, 'reembolso_externo_registrado', ['reembolso' => $r->uuid, 'refund_id' => $refundId]);
            $this->actualizarPago($p);

            return $r;
        });
    }

    // ───────────────────────── Internos ─────────────────────────

    /**
     * USD del reembolso con la tasa ORIGINAL; el último reembolso usa el USD
     * restante exacto (sin deriva por redondeo).
     */
    private function importeProveedor(Pago $pago, Dinero $monto, Dinero $disponible): Dinero
    {
        $usdPago = $pago->importeProveedor();
        $usdComprometido = Dinero::cero($usdPago->moneda);
        foreach ($pago->reembolsos()->get() as $r) {
            if ($r->estado->comprometeImporte() && $r->monto_proveedor !== null) {
                $usdComprometido = $usdComprometido->sumar(Dinero::deDecimal((string) $r->monto_proveedor, (string) $r->moneda_proveedor));
            }
        }
        $usdRestante = $usdPago->restar($usdComprometido);

        if ($monto->igualA($disponible)) {
            return $usdRestante;
        }

        $usd = $pago->tasa_aplicada !== null
            ? $monto->convertirDividiendo((string) $pago->tasa_aplicada, $usdPago->moneda)
            : Dinero::deCentavos($monto->centavos, $usdPago->moneda); // histórico sin tasa: mismos importes registrados

        return $usd->mayorQue($usdRestante) ? $usdRestante : $usd;
    }

    private function resolver(Reembolso $reembolso, EstadoReembolso $estado, array $cambios, array $datos, ?Usuario $usuario): Reembolso
    {
        return DB::transaction(function () use ($reembolso, $estado, $cambios, $datos, $usuario) {
            $r = Reembolso::whereKey($reembolso->id)->lockForUpdate()->firstOrFail();
            if ($r->estado === $estado) {
                if ($cambios) {
                    $r->update($cambios);
                }
                return $r;
            }
            if (!$r->estado->puedePasarA($estado)) {
                return $r; // ya resuelto por otra vía
            }

            if ($estado === EstadoReembolso::Completado) {
                $cambios['completado_at'] = now();
            }
            $this->cambiarEstado($r, $estado, $cambios, $usuario, $datos);

            if ($estado === EstadoReembolso::Completado) {
                $this->actualizarPago($r->pago, $usuario);
            }

            return $r;
        });
    }

    private function cambiarEstado(Reembolso $r, EstadoReembolso $nuevo, array $cambios, ?Usuario $usuario, array $datos = []): void
    {
        $anterior = $r->estado;
        if ($anterior !== $nuevo && !$anterior->puedePasarA($nuevo)) {
            throw new TransicionInvalida("Transición de reembolso no permitida: {$anterior->value} → {$nuevo->value}.");
        }

        $r->fill($cambios + ['estado' => $nuevo])->save();

        $this->pagos->evento($r->pago, 'reembolso_' . $nuevo->value, $datos + [
            'reembolso' => $r->uuid, 'de' => $anterior->value, 'a' => $nuevo->value, 'monto' => (string) $r->monto,
        ], $usuario);

        Bitacora::registrar('REEMBOLSO_' . strtoupper($nuevo->value), 'reembolsos', (int) $r->id, "Reembolso {$r->uuid} ({$r->monto} BOB) del pago {$r->pago->uuid}: {$anterior->value} → {$nuevo->value}.");
    }

    /**
     * Recalcula el estado del dinero del pago según los reembolsos completados.
     */
    private function actualizarPago(Pago $pago, ?Usuario $usuario = null): void
    {
        $p = $this->pagos->bloquear($pago->id);

        $completado = Dinero::cero('BOB');
        foreach ($p->reembolsos()->where('estado', EstadoReembolso::Completado->value)->get() as $r) {
            $completado = $completado->sumar($r->importe());
        }

        if (!$completado->esPositivo() || $p->estado === EstadoPago::Revertido) {
            return;
        }

        $nuevo = $completado->centavos >= $p->importe()->centavos ? EstadoPago::Reembolsado : EstadoPago::ReembolsadoParcial;
        if ($nuevo !== $p->estado && $p->estado->puedePasarA($nuevo)) {
            $this->pagos->transicionar($p, 'estado_por_reembolso', $nuevo, null, [], ['reembolsado' => $completado->decimal()], $usuario);
        }

        if ($p->reserva_id !== null) {
            app(AplicadorReserva::class)->recalcular((int) $p->reserva_id);
        }
    }
}
