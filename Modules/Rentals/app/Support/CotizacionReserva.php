<?php

namespace Modules\Rentals\Support;

use Carbon\CarbonImmutable;
use Modules\Finance\Support\Dinero;

/**
 * Cotización inmutable de una reserva: paquetes, horas y precios CONGELADOS en
 * el momento de cotizar (después se copian a reserva_paquetes), más las
 * unidades de cada equipo que requiere el conjunto de paquetes.
 */
final class CotizacionReserva
{
    /**
     * @param array<int, array{paquete_id:int, codigo:string, nombre:string, cantidad:int, precio_hora:string, horas:int, subtotal:string}> $lineas
     * @param array<int, int> $requerimientos maquinaria_id => unidades
     */
    public function __construct(
        public readonly CarbonImmutable $inicio,
        public readonly CarbonImmutable $fin,
        public readonly int $horas,
        public readonly array $lineas,
        public readonly Dinero $total,
        public readonly array $requerimientos,
    ) {
    }

    /** Filas para reserva_paquetes (precio congelado). */
    public function filasReservaPaquetes(): array
    {
        return array_map(fn ($l) => [
            'paquete_id' => $l['paquete_id'],
            'cantidad' => $l['cantidad'],
            'precio_hora' => $l['precio_hora'],
            'horas' => $l['horas'],
            'subtotal' => $l['subtotal'],
        ], array_values($this->lineas));
    }

    public function toArray(): array
    {
        return [
            'inicio' => $this->inicio->toDateTimeString(),
            'fin' => $this->fin->toDateTimeString(),
            'horas' => $this->horas,
            'lineas' => array_values($this->lineas),
            'total' => $this->total->decimal(),
            'moneda' => $this->total->moneda,
            'requerimientos' => $this->requerimientos,
        ];
    }
}
