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
use Modules\Finance\Gateways\Resultados\ResultadoReembolso;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Support\Dinero;

/**
 * Registro de reembolsos (solo lectura e integridad con PayPal).
 *
 * SOUND POS no gestiona reembolsos: no se solicitan, autorizan, ejecutan ni
 * entregan desde la aplicación, y no existe ninguna operación que devuelva
 * dinero. Este servicio solo:
 *  - registra los reembolsos que se hacen FUERA de la aplicación (panel de
 *    PayPal, contracargos), notificados por webhook, para que el estado del
 *    pago refleje el dinero realmente cobrado;
 *  - aplica el resultado informado por PayPal a un reembolso ya existente en el
 *    historial (webhook o consulta de la conciliación de PayPal);
 *  - recalcula el estado del pago y, si es de una reserva, su estado de pago.
 *
 * Los reembolsos anteriores se conservan como historial y nunca se eliminan.
 */
class ReembolsoService
{
    public function __construct(private readonly PagoService $pagos)
    {
    }

    /** Importe del pago aún no cubierto por reembolsos en curso o completados. */
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

    /**
     * Resultado del proveedor para un reembolso del historial (webhook o
     * consulta de la conciliación de PayPal).
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
