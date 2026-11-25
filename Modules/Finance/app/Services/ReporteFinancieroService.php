<?php

namespace Modules\Finance\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Models\Pago;
use Modules\Finance\Support\Dinero;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\MetodoPago;

/**
 * Reportes financieros (Etapa 3, D8: pantalla y CSV), todos por período.
 *
 * Cada reporte devuelve la misma forma ['columnas' => [...], 'filas' => [[...]]]
 * para que la pantalla y el CSV muestren exactamente los mismos datos.
 * Importes sumados con Dinero (centavos) y fechas agrupadas en PHP, sin
 * funciones SQL propias de un motor: mismo resultado en SQLite y MySQL.
 *
 * SOUND POS no incluye reportes de conciliación ni de reembolsos.
 */
class ReporteFinancieroService
{
    public const REPORTES = [
        'cobros_por_dia' => 'Cobros por día y método',
        'cobros_por_usuario' => 'Cobros por usuario y método',
        'diferencias' => 'Cajas con diferencia',
        'movimientos' => 'Movimientos manuales de caja',
    ];

    public function generar(string $reporte, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        if (!array_key_exists($reporte, self::REPORTES)) {
            throw new \InvalidArgumentException("Reporte desconocido: {$reporte}");
        }
        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->endOfDay();

        return match ($reporte) {
            'cobros_por_dia' => $this->cobrosPorDia($desde, $hasta),
            'cobros_por_usuario' => $this->cobrosPorUsuario($desde, $hasta),
            'diferencias' => $this->diferencias($desde, $hasta),
            'movimientos' => $this->movimientos($desde, $hasta),
        };
    }

    // ───────────────────────── Período ─────────────────────────

    /** Dinero recibido (por fecha de recepción), agrupado por día y método. */
    private function cobrosPorDia(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $metodos = $this->metodos();
        $grupos = [];
        foreach ($this->pagosRecibidos($desde, $hasta) as $p) {
            $clave = $p->recibido_at->format('Y-m-d') . '|' . ($metodos[$p->metodo_pago_id] ?? $p->metodo_pago_id);
            $grupos[$clave] ??= ['n' => 0, 'total' => Dinero::cero('BOB')];
            $grupos[$clave]['n']++;
            $grupos[$clave]['total'] = $grupos[$clave]['total']->sumar($p->importe());
        }
        ksort($grupos);

        $filas = [];
        foreach ($grupos as $clave => $g) {
            [$fecha, $metodo] = explode('|', $clave, 2);
            $filas[] = [$fecha, $metodo, $g['n'], $g['total']->decimal()];
        }

        return $this->conTotal(['Fecha', 'Método', 'Operaciones', 'Importe (BOB)'], $filas, 3, 2);
    }

    /** Dinero recibido por quién lo registró (los pagos en línea no tienen cajero). */
    private function cobrosPorUsuario(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $metodos = $this->metodos();
        $pagos = $this->pagosRecibidos($desde, $hasta);
        // Solo los nombres de quienes registraron pagos en el período (no toda la tabla)
        $cis = $pagos->pluck('ci_registrado_por')->filter()->unique()->values();
        $nombres = $cis->isEmpty() ? collect() : DB::table('usuario')->whereIn('ci', $cis)->pluck('nombre', 'ci');
        $grupos = [];
        foreach ($pagos as $p) {
            $usuario = $p->ci_registrado_por !== null ? ($nombres[$p->ci_registrado_por] ?? $p->ci_registrado_por) : 'En línea (cliente)';
            $clave = $usuario . '|' . ($metodos[$p->metodo_pago_id] ?? $p->metodo_pago_id);
            $grupos[$clave] ??= ['n' => 0, 'total' => Dinero::cero('BOB')];
            $grupos[$clave]['n']++;
            $grupos[$clave]['total'] = $grupos[$clave]['total']->sumar($p->importe());
        }
        ksort($grupos);

        $filas = [];
        foreach ($grupos as $clave => $g) {
            [$usuario, $metodo] = explode('|', $clave, 2);
            $filas[] = [$usuario, $metodo, $g['n'], $g['total']->decimal()];
        }

        return $this->conTotal(['Usuario', 'Método', 'Operaciones', 'Importe (BOB)'], $filas, 3, 2);
    }

    private function diferencias(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $filas = Caja::with(['user', 'cerradoPor'])
            ->where('estado', Caja::CERRADA)
            ->whereBetween('fecha_cierre', [$desde, $hasta])
            ->whereNotNull('diferencia')->where('diferencia', '!=', 0)
            ->orderBy('fecha_cierre')->get()
            ->map(fn (Caja $c) => [
                $c->id,
                $c->user?->nombre ?? $c->user_id,
                $c->fecha_cierre?->format('Y-m-d H:i'),
                $c->efectivo_esperado ?? '',
                $c->efectivo_contado ?? $c->monto_cierre ?? '',
                $c->diferencia,
                $c->cerradoPor?->nombre ?? '',
                $c->cierre_supervisado ? 'sí' : 'no',
                $c->esHeredada() ? 'heredado' : 'por método',
                $c->observaciones ?? '',
            ])->all();

        return [
            'columnas' => ['Caja', 'Titular', 'Cierre', 'Esperado', 'Contado', 'Diferencia', 'Cerrada por', 'Supervisado', 'Arqueo', 'Observaciones'],
            'filas' => $filas,
        ];
    }

    private function movimientos(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $filas = MovimientoCaja::with('registradoPor')
            ->whereBetween('created_at', [$desde, $hasta])->orderBy('id')->get()
            ->map(fn (MovimientoCaja $m) => [
                $m->created_at?->format('Y-m-d H:i'),
                $m->caja_id,
                $m->tipo,
                $m->monto,
                $m->motivo,
                $m->registradoPor?->nombre ?? $m->ci_registrado_por,
                $m->revierte_id ?? '',
                $m->caja_corregida_id ?? '',
            ])->all();

        return [
            'columnas' => ['Fecha', 'Caja', 'Tipo', 'Importe (BOB)', 'Motivo', 'Registrado por', 'Revierte al movimiento', 'Corrige la caja'],
            'filas' => $filas,
        ];
    }

    // ───────────────────────── Internos ─────────────────────────

    private function pagosRecibidos(CarbonInterface $desde, CarbonInterface $hasta)
    {
        $recibidos = array_map(fn ($e) => $e->value, array_filter(EstadoPago::cases(), fn ($e) => $e->dineroRecibido()));

        return Pago::whereIn('estado', $recibidos)
            ->whereNotNull('recibido_at')
            ->whereBetween('recibido_at', [$desde, $hasta])
            ->orderBy('recibido_at')->get();
    }

    private function metodos(): array
    {
        return MetodoPago::query()->pluck('nombre', 'id')->all();
    }

    /** Añade una fila de total (cantidad e importe) al final. */
    private function conTotal(array $columnas, array $filas, int $colImporte, int $colCantidad): array
    {
        if ($filas !== []) {
            $total = Dinero::cero('BOB');
            $n = 0;
            foreach ($filas as $f) {
                $total = $total->sumar(Dinero::deDecimal((string) $f[$colImporte], 'BOB'));
                $n += (int) $f[$colCantidad];
            }
            $fila = array_fill(0, count($columnas), '');
            $fila[0] = 'TOTAL';
            $fila[$colCantidad] = $n;
            $fila[$colImporte] = $total->decimal();
            $filas[] = $fila;
        }

        return ['columnas' => $columnas, 'filas' => $filas];
    }
}
