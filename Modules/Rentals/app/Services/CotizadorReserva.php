<?php

namespace Modules\Rentals\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Finance\Support\Dinero;
use Modules\Rentals\Exceptions\CotizacionInvalida;
use Modules\Rentals\Models\Paquete;
use Modules\Rentals\Support\CotizacionReserva;

/**
 * Cotiza una reserva (Etapa 4, paso 2). Reglas:
 *  - Solo paquetes: no hay tarifa por equipo suelto ni por día.
 *  - Solo horas completas: la duración [inicio, fin) debe ser un número exacto
 *    de horas, al menos 1.
 *  - Cada paquete debe existir (sin baja lógica), estar activo, tener equipos,
 *    estar en BOB y admitir esas horas (horas_minimas ≤ horas ≤ horas_maximas).
 *  - Subtotal = precio_hora × horas × cantidad, calculado en centavos (Dinero).
 *  - Un paquete repetido en la solicitud se agrupa sumando cantidades.
 *
 * No consulta disponibilidad ni reserva equipos: eso es DisponibilidadService
 * y, al confirmar, ReservaService (paso 3).
 */
class CotizadorReserva
{
    public const MONEDA = 'BOB';

    /**
     * @param array<int, array{paquete_id:int|string, cantidad?:int|string}> $lineas
     */
    public function cotizar(array $lineas, CarbonInterface|string $inicio, CarbonInterface|string $fin): CotizacionReserva
    {
        [$a, $b, $horas] = $this->intervalo($inicio, $fin);
        $pedidos = $this->agrupar($lineas);

        $paquetes = Paquete::query()
            ->with('items.maquinaria')
            ->whereIn('id', array_keys($pedidos))
            ->get()
            ->keyBy('id');

        $detalle = [];
        $requerimientos = [];
        $totalCentavos = 0;

        foreach ($pedidos as $paqueteId => $cantidad) {
            $paquete = $paquetes->get($paqueteId);
            if (!$paquete) {
                throw new CotizacionInvalida("El paquete {$paqueteId} no existe o fue dado de baja.");
            }
            $this->validarPaquete($paquete, $horas);

            $precio = Dinero::deDecimal((string) $paquete->precio_hora, self::MONEDA);
            $subtotal = Dinero::deCentavos($precio->centavos * $horas * $cantidad, self::MONEDA);
            $totalCentavos += $subtotal->centavos;

            $detalle[$paqueteId] = [
                'paquete_id' => (int) $paquete->id,
                'codigo' => (string) $paquete->codigo,
                'nombre' => (string) $paquete->nombre,
                'cantidad' => $cantidad,
                'precio_hora' => $precio->decimal(),
                'horas' => $horas,
                'subtotal' => $subtotal->decimal(),
            ];

            foreach ($paquete->items as $item) {
                $requerimientos[(int) $item->maquinaria_id] = ($requerimientos[(int) $item->maquinaria_id] ?? 0)
                    + (int) $item->cantidad * $cantidad;
            }
        }
        ksort($requerimientos);

        return new CotizacionReserva($a, $b, $horas, $detalle, Dinero::deCentavos($totalCentavos, self::MONEDA), $requerimientos);
    }

    /** @return array{CarbonImmutable, CarbonImmutable, int} */
    private function intervalo(CarbonInterface|string $inicio, CarbonInterface|string $fin): array
    {
        try {
            $a = CarbonImmutable::parse($inicio)->setTimezone(config('app.timezone'));
            $b = CarbonImmutable::parse($fin)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            throw new CotizacionInvalida('Las fechas de inicio y fin no son válidas.');
        }

        if ($b->lessThanOrEqualTo($a)) {
            throw new CotizacionInvalida('La hora de fin debe ser posterior a la de inicio.');
        }
        if ($a->second !== 0 || $a->micro !== 0 || $b->second !== 0 || $b->micro !== 0) {
            throw new CotizacionInvalida('Las horas de inicio y fin no pueden tener segundos.');
        }

        $minutos = (int) $a->diffInMinutes($b, true);
        if ($minutos % 60 !== 0) {
            throw new CotizacionInvalida('El alquiler es solo por horas completas: la duración debe ser un número exacto de horas.');
        }

        return [$a, $b, intdiv($minutos, 60)];
    }

    /** @return array<int, int> paquete_id => cantidad */
    private function agrupar(array $lineas): array
    {
        if ($lineas === []) {
            throw new CotizacionInvalida('La reserva debe incluir al menos un paquete.');
        }

        $pedidos = [];
        foreach ($lineas as $linea) {
            $id = $linea['paquete_id'] ?? null;
            $cantidad = $linea['cantidad'] ?? 1;
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                throw new CotizacionInvalida('Cada línea debe indicar un paquete válido.');
            }
            if (filter_var($cantidad, FILTER_VALIDATE_INT) === false || (int) $cantidad <= 0) {
                throw new CotizacionInvalida('La cantidad de cada paquete debe ser un entero mayor que cero.');
            }
            $pedidos[(int) $id] = ($pedidos[(int) $id] ?? 0) + (int) $cantidad;
        }
        ksort($pedidos);

        return $pedidos;
    }

    private function validarPaquete(Paquete $paquete, int $horas): void
    {
        $nombre = "«{$paquete->nombre}»";

        if (!$paquete->activo) {
            throw new CotizacionInvalida("El paquete {$nombre} no está disponible para alquiler.");
        }
        if ($paquete->moneda !== self::MONEDA) {
            throw new CotizacionInvalida("El paquete {$nombre} no tiene precio en " . self::MONEDA . '.');
        }
        if ($paquete->items->isEmpty()) {
            throw new CotizacionInvalida("El paquete {$nombre} no tiene equipos asignados.");
        }
        if ($horas < (int) $paquete->horas_minimas) {
            throw new CotizacionInvalida("El paquete {$nombre} se alquila como mínimo {$paquete->horas_minimas} hora(s).");
        }
        if ($paquete->horas_maximas !== null && $horas > (int) $paquete->horas_maximas) {
            throw new CotizacionInvalida("El paquete {$nombre} se alquila como máximo {$paquete->horas_maximas} hora(s).");
        }
    }
}
