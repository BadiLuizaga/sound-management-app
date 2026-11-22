<?php

namespace Modules\Finance\Support;

/**
 * Resultado del arqueo de una caja. Importes como Dinero (centavos enteros).
 *
 * efectivoEsperado = fondo + efectivoCobrado − reembolsosEfectivo + ingresos − egresos
 * Los demás métodos (tarjeta, transferencia, QR, PayPal) son informativos:
 * no forman parte del efectivo.
 */
final class ResultadoArqueo
{
    /**
     * @param array<string, array{nombre: string, recibido: Dinero, operaciones: int, pendiente: Dinero, pendientes: int, rechazado: Dinero, rechazados: int}> $metodos
     * @param array<string, array{monto: Dinero, cantidad: int}> $reembolsosPorMedio
     * @param list<array<string, mixed>> $pendientes  transferencias / QR sin verificar
     * @param list<array<string, mixed>> $alquileres  alquileres heredados (D1: fuera del arqueo)
     */
    public function __construct(
        public readonly Dinero $fondo,
        public readonly Dinero $efectivoCobrado,
        public readonly Dinero $reembolsosEfectivo,
        public readonly Dinero $ingresos,
        public readonly Dinero $egresos,
        public readonly Dinero $efectivoEsperado,
        public readonly array $metodos,
        public readonly array $reembolsosPorMedio,
        public readonly array $pendientes,
        public readonly array $alquileres,
    ) {
    }

    public function totalPendiente(): Dinero
    {
        $total = Dinero::cero('BOB');
        foreach ($this->metodos as $m) {
            $total = $total->sumar($m['pendiente']);
        }

        return $total;
    }

    /** Foto serializable que se guarda en cajas.resumen al cerrar. */
    public function toArray(): array
    {
        $metodos = [];
        foreach ($this->metodos as $codigo => $m) {
            $metodos[$codigo] = [
                'nombre' => $m['nombre'],
                'recibido' => $m['recibido']->decimal(),
                'operaciones' => $m['operaciones'],
                'pendiente' => $m['pendiente']->decimal(),
                'pendientes' => $m['pendientes'],
                'rechazado' => $m['rechazado']->decimal(),
                'rechazados' => $m['rechazados'],
            ];
        }

        $reembolsos = [];
        foreach ($this->reembolsosPorMedio as $medio => $r) {
            $reembolsos[$medio] = ['monto' => $r['monto']->decimal(), 'cantidad' => $r['cantidad']];
        }

        return [
            'fondo' => $this->fondo->decimal(),
            'efectivo_cobrado' => $this->efectivoCobrado->decimal(),
            'reembolsos_efectivo' => $this->reembolsosEfectivo->decimal(),
            'ingresos' => $this->ingresos->decimal(),
            'egresos' => $this->egresos->decimal(),
            'efectivo_esperado' => $this->efectivoEsperado->decimal(),
            'metodos' => $metodos,
            'reembolsos_por_medio' => $reembolsos,
            'pendientes' => $this->pendientes,
            'alquileres_fuera_del_arqueo' => $this->alquileres,
        ];
    }
}
