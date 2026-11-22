<?php

namespace Modules\Finance\Services;

use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Support\ResultadoArqueo;
use Modules\Rentals\Models\Alquiler;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\MetodoPago;

/**
 * Arqueo de una caja (Etapa 3). Solo usa registros ligados a su caja_id:
 *
 *   efectivo esperado = fondo
 *                     + pagos EFECTIVO de la caja con dinero recibido
 *                       (recibido, reembolsado_parcial, reembolsado, revertido)
 *                     − reembolsos completados entregados en EFECTIVO desde la caja
 *                     + ingresos manuales − egresos manuales
 *
 * Un pago reembolsado cuenta por su importe original y el reembolso se resta
 * aparte (nunca dos veces). Tarjeta, transferencia, QR y PayPal se informan
 * por separado y no entran al efectivo.
 */
class ArqueoService
{
    /** Orden de presentación de los métodos. */
    private const METODOS = [MetodoPago::EFECTIVO, MetodoPago::TARJETA, MetodoPago::TRANSFERENCIA, MetodoPago::QR, MetodoPago::PAYPAL];

    public function calcular(Caja $caja): ResultadoArqueo
    {
        $catalogo = MetodoPago::query()->get()->keyBy('id');

        $metodos = [];
        foreach (self::METODOS as $codigo) {
            $metodo = $catalogo->firstWhere('codigo', $codigo);
            $metodos[$codigo] = [
                'nombre' => $metodo?->nombre ?? $codigo,
                'recibido' => Dinero::cero('BOB'), 'operaciones' => 0,
                'pendiente' => Dinero::cero('BOB'), 'pendientes' => 0,
                'rechazado' => Dinero::cero('BOB'), 'rechazados' => 0,
            ];
        }

        $pendientes = [];
        $pagos = Pago::where('caja_id', $caja->id)->orderBy('id')->get();
        foreach ($pagos as $p) {
            $codigo = (string) ($catalogo[$p->metodo_pago_id]->codigo ?? 'OTRO');
            if (!isset($metodos[$codigo])) {
                $metodos[$codigo] = [
                    'nombre' => $codigo, 'recibido' => Dinero::cero('BOB'), 'operaciones' => 0,
                    'pendiente' => Dinero::cero('BOB'), 'pendientes' => 0, 'rechazado' => Dinero::cero('BOB'), 'rechazados' => 0,
                ];
            }
            $importe = $p->importe();

            if ($p->estado->dineroRecibido()) {
                $metodos[$codigo]['recibido'] = $metodos[$codigo]['recibido']->sumar($importe);
                $metodos[$codigo]['operaciones']++;
            } elseif ($p->estado === EstadoPago::PendienteVerificacion) {
                $metodos[$codigo]['pendiente'] = $metodos[$codigo]['pendiente']->sumar($importe);
                $metodos[$codigo]['pendientes']++;
                $pendientes[] = [
                    'pago' => $p->uuid,
                    'metodo' => $codigo,
                    'monto' => $importe->decimal(),
                    'referencia' => $p->referencia_externa,
                    'registrado_at' => $p->created_at?->toDateTimeString(),
                ];
            } elseif ($p->estado === EstadoPago::Rechazado) {
                $metodos[$codigo]['rechazado'] = $metodos[$codigo]['rechazado']->sumar($importe);
                $metodos[$codigo]['rechazados']++;
            }
        }

        $reembolsosPorMedio = [];
        $reembolsosEfectivo = Dinero::cero('BOB');
        $reembolsos = Reembolso::where('caja_id', $caja->id)
            ->where('estado', EstadoReembolso::Completado->value)
            ->orderBy('id')->get();
        foreach ($reembolsos as $r) {
            $medio = (string) ($r->medio_devolucion ?? 'sin_medio');
            $reembolsosPorMedio[$medio] ??= ['monto' => Dinero::cero('BOB'), 'cantidad' => 0];
            $reembolsosPorMedio[$medio]['monto'] = $reembolsosPorMedio[$medio]['monto']->sumar($r->importe());
            $reembolsosPorMedio[$medio]['cantidad']++;
            if ($r->medio_devolucion === Reembolso::MEDIO_EFECTIVO) {
                $reembolsosEfectivo = $reembolsosEfectivo->sumar($r->importe());
            }
        }

        $ingresos = Dinero::cero('BOB');
        $egresos = Dinero::cero('BOB');
        foreach (MovimientoCaja::where('caja_id', $caja->id)->orderBy('id')->get() as $m) {
            if ($m->tipo === MovimientoCaja::INGRESO) {
                $ingresos = $ingresos->sumar($m->importe());
            } else {
                $egresos = $egresos->sumar($m->importe());
            }
        }

        $fondo = $caja->fondo();
        $efectivoCobrado = $metodos[MetodoPago::EFECTIVO]['recibido'];
        $esperado = $fondo->sumar($efectivoCobrado)->restar($reembolsosEfectivo)->sumar($ingresos)->restar($egresos);

        return new ResultadoArqueo(
            fondo: $fondo,
            efectivoCobrado: $efectivoCobrado,
            reembolsosEfectivo: $reembolsosEfectivo,
            ingresos: $ingresos,
            egresos: $egresos,
            efectivoEsperado: $esperado,
            metodos: $metodos,
            reembolsosPorMedio: $reembolsosPorMedio,
            pendientes: $pendientes,
            alquileres: $this->alquileresHeredados($caja, $catalogo),
        );
    }

    /**
     * D1: los alquileres heredados no registran pagos, así que NO entran al
     * arqueo. Se listan (importes y método) para que el cajero los cuente aparte.
     */
    private function alquileresHeredados(Caja $caja, $catalogo): array
    {
        $hasta = $caja->fecha_cierre ?? now();

        return Alquiler::where('ci_empleado', $caja->user_id)
            ->where('created_at', '>=', $caja->fecha_apertura)
            ->where('created_at', '<=', $hasta)
            ->orderBy('id')->get()
            ->map(fn ($a) => [
                'alquiler' => (int) $a->id,
                'metodo' => (string) ($catalogo[$a->metodo_pago_id]->nombre ?? $a->metodo_pago_id),
                'total_estimado' => Dinero::deDecimal((string) $a->total_estimado, 'BOB')->decimal(),
                'total_real' => $a->total_real !== null ? Dinero::deDecimal((string) $a->total_real, 'BOB')->decimal() : null,
                'garantia' => Dinero::deDecimal((string) $a->monto_garantia, 'BOB')->decimal(),
                'estado' => (string) $a->estado,
            ])->values()->all();
    }
}
