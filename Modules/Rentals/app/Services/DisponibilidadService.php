<?php

namespace Modules\Rentals\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Rentals\Models\Maquinaria;
use Modules\Rentals\Models\MaquinariaBloqueo;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Support\CotizacionReserva;
use Modules\Rentals\Support\ResultadoDisponibilidad;

/**
 * Disponibilidad de equipos por intervalo (Etapa 4, paso 2).
 *
 * Intervalos semiabiertos [inicio, fin). Para una solicitud [a, b) y un margen
 * de preparación M (C1, D4), las unidades ocupadas de un equipo son el PICO de
 * uso simultáneo de:
 *
 *  - reserva_equipos 'reservado' (reservas confirmadas o en curso), dentro de la
 *    ventana [a − M, b + M): así una reserva [s, e) choca si s − M < b y e + M > a.
 *    Una reserva en curso, vencida y sin devolver ocupa hasta que se devuelva;
 *  - alquileres heredados 'activo' o 'atrasado' (D1): cada detalle ocupa una
 *    unidad desde su inicio y hasta que se devuelva, con el mismo margen;
 *  - maquinaria_bloqueos 'activo' (mantenimiento, daño…), dentro de [a, b) y SIN
 *    margen; `hasta` nulo = indefinido.
 *
 * Se usa el pico (no la suma) para que dos reservas consecutivas sobre la misma
 * unidad no cuenten como dos unidades. Un equipo en estado 'mantenimiento'
 * (fuera de servicio total) no tiene unidades disponibles.
 *
 * Con $bloquear = true, las filas de `maquinarias` se bloquean (FOR UPDATE)
 * ordenadas por id antes de leer la ocupación: es el punto de serialización
 * que usa la confirmación (paso 3). Exige una transacción abierta.
 *
 * Con $bloquear, la ocupación también se lee con lecturas BLOQUEANTES (FOR
 * UPDATE). En MySQL (REPEATABLE READ) una lectura normal usa la "foto" fijada
 * en la primera lectura de la transacción, que puede ser anterior a obtener el
 * bloqueo de las maquinarias: no vería las unidades que otra confirmación
 * acaba de reservar y habría sobreventa (lo detectó la prueba con dos procesos,
 * paso 3). Una lectura bloqueante siempre lee la última versión confirmada.
 */
class DisponibilidadService
{
    public const ESTADOS_HEREDADOS_OCUPAN = ['activo', 'atrasado'];

    public function margenMinutos(): int
    {
        return max(0, (int) config('rentals.margen_preparacion_minutos', 60));
    }

    /**
     * @param array<int|string, int|string> $solicitadas maquinaria_id => unidades
     */
    public function consultar(
        array $solicitadas,
        CarbonInterface|string $inicio,
        CarbonInterface|string $fin,
        ?int $excluirReservaId = null,
        bool $bloquear = false,
    ): ResultadoDisponibilidad {
        $a = $this->instante($inicio);
        $b = $this->instante($fin);
        if ($b->lessThanOrEqualTo($a)) {
            throw new InvalidArgumentException('El fin del intervalo debe ser posterior al inicio.');
        }
        $pedidas = $this->normalizarSolicitud($solicitadas);
        if ($bloquear && DB::transactionLevel() === 0) {
            throw new LogicException('Bloquear la disponibilidad exige una transacción abierta.');
        }

        $margen = $this->margenMinutos();
        $ventanaInicio = $a->subMinutes($margen);
        $ventanaFin = $b->addMinutes($margen);
        $ids = array_keys($pedidas);

        $consulta = Maquinaria::query()->whereIn('id', $ids)->orderBy('id');
        if ($bloquear) {
            $consulta->lockForUpdate();
        }
        $maquinarias = $consulta->get(['id', 'codigo', 'nombre', 'estado', 'cantidad_total'])->keyBy('id');
        foreach ($ids as $id) {
            if (!$maquinarias->has($id)) {
                throw new InvalidArgumentException("El equipo {$id} no existe.");
            }
        }

        // Tramos de ocupación por equipo: [inicio, fin|null, unidades, ventana]
        $tramos = array_fill_keys($ids, []);
        $ahora = CarbonImmutable::now();

        foreach ($this->reservasQueOcupan($ids, $ventanaInicio, $ventanaFin, $excluirReservaId, $bloquear) as $fila) {
            $finReal = $this->instante($fila->fecha_fin);
            $pendienteDeDevolver = $fila->estado_reserva === Reserva::ESTADO_EN_CURSO && $fila->devuelta_at === null;
            $tramos[$fila->maquinaria_id][] = [
                $this->instante($fila->fecha_inicio),
                ($pendienteDeDevolver && $finReal->lessThan($ahora)) ? null : $finReal,
                (int) $fila->cantidad,
                'ampliada',
            ];
        }

        foreach ($this->alquileresHeredados($ids, $ventanaFin, $bloquear) as $fila) {
            $tramos[$fila->maquinaria_id][] = [$this->instante($fila->fecha_inicio), null, 1, 'ampliada'];
        }

        foreach ($this->bloqueos($ids, $a, $b, $bloquear) as $bloqueo) {
            $tramos[$bloqueo->maquinaria_id][] = [
                CarbonImmutable::instance($bloqueo->desde),
                $bloqueo->hasta ? CarbonImmutable::instance($bloqueo->hasta) : null,
                (int) $bloqueo->cantidad,
                'estricta',
            ];
        }

        $equipos = [];
        foreach ($ids as $id) {
            $m = $maquinarias[$id];
            $fueraDeServicio = $m->estado === 'mantenimiento';
            $total = max(0, (int) $m->cantidad_total);
            $ocupadas = $this->pico($tramos[$id], $a, $b, $ventanaInicio, $ventanaFin);
            $disponibles = $fueraDeServicio ? 0 : max(0, $total - $ocupadas);

            $equipos[$id] = [
                'maquinaria_id' => (int) $id,
                'codigo' => (string) $m->codigo,
                'nombre' => (string) $m->nombre,
                'total' => $total,
                'ocupadas' => $ocupadas,
                'disponibles' => $disponibles,
                'solicitadas' => $pedidas[$id],
                'faltan' => max(0, $pedidas[$id] - $disponibles),
                'fuera_de_servicio' => $fueraDeServicio,
            ];
        }

        return new ResultadoDisponibilidad($a, $b, $margen, $equipos);
    }

    /** Disponibilidad de los equipos que requiere una cotización (todos sus paquetes). */
    public function paraCotizacion(CotizacionReserva $cotizacion, ?int $excluirReservaId = null, bool $bloquear = false): ResultadoDisponibilidad
    {
        return $this->consultar($cotizacion->requerimientos, $cotizacion->inicio, $cotizacion->fin, $excluirReservaId, $bloquear);
    }

    /** Unidades libres de un equipo en el intervalo. */
    public function disponibles(int $maquinariaId, CarbonInterface|string $inicio, CarbonInterface|string $fin, ?int $excluirReservaId = null): int
    {
        return $this->consultar([$maquinariaId => 1], $inicio, $fin, $excluirReservaId)->disponiblesDe($maquinariaId);
    }

    // ───────────────────────── Consultas ─────────────────────────

    /**
     * Unidades reservadas que pueden chocar. Solo se bloquea `reserva_equipos`
     * (no `reservas`): una cancelación bloquea primero su reserva y después sus
     * equipos, así que bloquear aquí las reservas ajenas podría interbloquear.
     * El estado de las reservas se lee aparte, sin bloqueo: solo sirve para la
     * regla «en curso, vencida y sin devolver».
     */
    private function reservasQueOcupan(array $ids, CarbonImmutable $ventanaInicio, CarbonImmutable $ventanaFin, ?int $excluir, bool $bloquear)
    {
        $ahora = CarbonImmutable::now()->toDateTimeString();

        $filas = DB::table('reserva_equipos')
            ->whereIn('maquinaria_id', $ids)
            ->where('estado', ReservaEquipo::ESTADO_RESERVADO)
            ->when($excluir !== null, fn ($q) => $q->where('reserva_id', '<>', $excluir))
            ->where('fecha_inicio', '<', $ventanaFin->toDateTimeString())
            ->where(fn ($q) => $q->where('fecha_fin', '>', $ventanaInicio->toDateTimeString())
                // ya vencidas: ocupan solo si siguen en curso sin devolver (se filtra abajo)
                ->orWhere('fecha_fin', '<', $ahora))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->get(['reserva_id', 'maquinaria_id', 'cantidad', 'fecha_inicio', 'fecha_fin']);

        $reservas = $filas->isEmpty() ? collect() : DB::table('reservas')
            ->whereIn('id', $filas->pluck('reserva_id')->unique()->values())
            ->get(['id', 'estado', 'devuelta_at'])
            ->keyBy('id');

        return $filas->map(function ($f) use ($reservas) {
            $r = $reservas->get($f->reserva_id);
            $f->estado_reserva = $r->estado ?? null;
            $f->devuelta_at = $r->devuelta_at ?? null;

            return $f;
        })->filter(function ($f) use ($ventanaInicio) {
            $dentro = $this->instante($f->fecha_fin)->greaterThan($ventanaInicio);
            $sinDevolver = $f->estado_reserva === Reserva::ESTADO_EN_CURSO && $f->devuelta_at === null;

            return $dentro || $sinDevolver;
        })->values();
    }

    private function alquileresHeredados(array $ids, CarbonImmutable $ventanaFin, bool $bloquear)
    {
        return DB::table('alquiler_detalles as d')
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->join('alquileres as a', 'a.id', '=', 'd.alquiler_id')
            ->whereIn('d.maquinaria_id', $ids)
            ->whereIn('a.estado', self::ESTADOS_HEREDADOS_OCUPAN)
            ->where('a.fecha_inicio', '<', $ventanaFin->toDateTimeString())
            ->get(['d.maquinaria_id', 'a.fecha_inicio']);
    }

    private function bloqueos(array $ids, CarbonImmutable $a, CarbonImmutable $b, bool $bloquear)
    {
        return MaquinariaBloqueo::query()
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->whereIn('maquinaria_id', $ids)
            ->where('estado', MaquinariaBloqueo::ESTADO_ACTIVO)
            ->where('desde', '<', $b->toDateTimeString())
            ->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>', $a->toDateTimeString()))
            ->get(['maquinaria_id', 'cantidad', 'desde', 'hasta']);
    }

    // ───────────────────────── Cálculo ─────────────────────────

    /**
     * Máximo de unidades ocupadas a la vez. Cada tramo se recorta a su ventana:
     * 'ampliada' = [a − M, b + M) (reservas y heredados), 'estricta' = [a, b)
     * (bloqueos). En un mismo instante, las salidas se procesan antes que las
     * entradas: [10:00, 12:00) y [12:00, 14:00) no se solapan.
     */
    private function pico(array $tramos, CarbonImmutable $a, CarbonImmutable $b, CarbonImmutable $ventanaInicio, CarbonImmutable $ventanaFin): int
    {
        $eventos = [];
        foreach ($tramos as [$inicio, $fin, $unidades, $ventana]) {
            [$desde, $hasta] = $ventana === 'estricta' ? [$a, $b] : [$ventanaInicio, $ventanaFin];
            $ini = max($inicio->getTimestamp(), $desde->getTimestamp());
            $fi = min($fin?->getTimestamp() ?? PHP_INT_MAX, $hasta->getTimestamp());
            if ($ini < $fi && $unidades > 0) {
                $eventos[] = [$ini, $unidades];
                $eventos[] = [$fi, -$unidades];
            }
        }

        usort($eventos, fn ($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);

        $actual = 0;
        $maximo = 0;
        foreach ($eventos as [, $delta]) {
            $actual += $delta;
            $maximo = max($maximo, $actual);
        }

        return $maximo;
    }

    /** @return array<int, int> maquinaria_id => unidades, ordenado por id */
    private function normalizarSolicitud(array $solicitadas): array
    {
        if ($solicitadas === []) {
            throw new InvalidArgumentException('Indica al menos un equipo.');
        }
        $pedidas = [];
        foreach ($solicitadas as $id => $unidades) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0
                || filter_var($unidades, FILTER_VALIDATE_INT) === false || (int) $unidades <= 0) {
                throw new InvalidArgumentException('Cada equipo necesita un id válido y al menos una unidad.');
            }
            $pedidas[(int) $id] = (int) $unidades;
        }
        ksort($pedidas);

        return $pedidas;
    }

    private function instante(CarbonInterface|string $valor): CarbonImmutable
    {
        return CarbonImmutable::parse($valor)->setTimezone(config('app.timezone'))->startOfSecond();
    }
}
