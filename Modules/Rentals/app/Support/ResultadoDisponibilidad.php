<?php

namespace Modules\Rentals\Support;

use Carbon\CarbonImmutable;

/**
 * Resultado inmutable de una consulta de disponibilidad.
 *
 * Por cada equipo: unidades totales, unidades ocupadas en el peor momento del
 * intervalo (pico), disponibles, solicitadas y faltantes.
 */
final class ResultadoDisponibilidad
{
    /**
     * @param array<int, array{maquinaria_id:int, codigo:string, nombre:string, total:int, ocupadas:int,
     *                         disponibles:int, solicitadas:int, faltan:int, fuera_de_servicio:bool}> $equipos
     */
    public function __construct(
        public readonly CarbonImmutable $inicio,
        public readonly CarbonImmutable $fin,
        public readonly int $margenMinutos,
        public readonly array $equipos,
    ) {
    }

    /** Hay unidades suficientes de todos los equipos solicitados. */
    public function alcanza(): bool
    {
        foreach ($this->equipos as $e) {
            if ($e['faltan'] > 0) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array{maquinaria_id:int, codigo:string, nombre:string, solicitadas:int, disponibles:int, faltan:int}> */
    public function faltantes(): array
    {
        $faltan = [];
        foreach ($this->equipos as $e) {
            if ($e['faltan'] > 0) {
                $faltan[] = [
                    'maquinaria_id' => $e['maquinaria_id'],
                    'codigo' => $e['codigo'],
                    'nombre' => $e['nombre'],
                    'solicitadas' => $e['solicitadas'],
                    'disponibles' => $e['disponibles'],
                    'faltan' => $e['faltan'],
                ];
            }
        }

        return $faltan;
    }

    public function disponiblesDe(int $maquinariaId): int
    {
        return $this->equipos[$maquinariaId]['disponibles'] ?? 0;
    }

    /** Texto para el motivo de rechazo (D2). */
    public function motivo(): ?string
    {
        if ($this->alcanza()) {
            return null;
        }

        return 'Sin disponibilidad: ' . implode('; ', array_map(
            fn ($f) => "{$f['nombre']} ({$f['codigo']}): se necesitan {$f['solicitadas']}, hay {$f['disponibles']}",
            $this->faltantes()
        )) . '.';
    }

    public function toArray(): array
    {
        return [
            'inicio' => $this->inicio->toDateTimeString(),
            'fin' => $this->fin->toDateTimeString(),
            'margen_minutos' => $this->margenMinutos,
            'alcanza' => $this->alcanza(),
            'equipos' => array_values($this->equipos),
        ];
    }
}
