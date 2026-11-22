<?php

namespace Modules\Finance\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Support\Dinero;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\MetodoPago;

/**
 * Reportes financieros y conciliación (Etapa 3, D8: pantalla y CSV).
 *
 * Cada reporte devuelve la misma forma ['columnas' => [...], 'filas' => [[...]]]
 * para que la pantalla y el CSV muestren exactamente los mismos datos.
 * Importes sumados con Dinero (centavos) y fechas agrupadas en PHP, sin
 * funciones SQL propias de un motor: mismo resultado en SQLite y MySQL.
 *
 * Los reportes de período filtran por fecha; los de conciliación muestran
 * el estado ACTUAL de lo pendiente, sin importar la fecha (salvo "venta sin
 * pago", que usa el período).
 */
class ReporteFinancieroService
{
    public const REPORTES = [
        'cobros_por_dia' => 'Cobros por día y método',
        'cobros_por_usuario' => 'Cobros por usuario y método',
        'reembolsos' => 'Reembolsos entregados',
        'diferencias' => 'Cajas con diferencia',
        'movimientos' => 'Movimientos manuales de caja',
        'conciliacion_pagos' => 'Conciliación de pagos',
        'conciliacion_reembolsos' => 'Conciliación de reembolsos',
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
            'reembolsos' => $this->reembolsos($desde, $hasta),
            'diferencias' => $this->diferencias($desde, $hasta),
            'movimientos' => $this->movimientos($desde, $hasta),
            'conciliacion_pagos' => $this->conciliacionPagos($desde, $hasta),
            'conciliacion_reembolsos' => $this->conciliacionReembolsos(),
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
        $nombres = DB::table('usuario')->pluck('nombre', 'ci');
        $grupos = [];
        foreach ($this->pagosRecibidos($desde, $hasta) as $p) {
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

    private function reembolsos(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $grupos = [];
        $consulta = Reembolso::where('estado', EstadoReembolso::Completado->value)
            ->whereBetween('completado_at', [$desde, $hasta])->orderBy('completado_at')->get();
        foreach ($consulta as $r) {
            $clave = $r->completado_at->format('Y-m-d') . '|' . ($r->medio_devolucion ?? 'sin medio registrado');
            $grupos[$clave] ??= ['n' => 0, 'total' => Dinero::cero('BOB')];
            $grupos[$clave]['n']++;
            $grupos[$clave]['total'] = $grupos[$clave]['total']->sumar($r->importe());
        }
        ksort($grupos);

        $filas = [];
        foreach ($grupos as $clave => $g) {
            [$fecha, $medio] = explode('|', $clave, 2);
            $filas[] = [$fecha, $medio, $g['n'], $g['total']->decimal()];
        }

        return $this->conTotal(['Fecha', 'Medio de devolución', 'Cantidad', 'Importe (BOB)'], $filas, 3, 2);
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

    // ───────────────────────── Conciliación ─────────────────────────

    private function conciliacionPagos(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $metodos = $this->metodos();
        $ahora = now();
        $filas = [];
        $horas = fn ($fecha) => $fecha ? (int) floor($fecha->diffInMinutes($ahora, true) / 60) : '';

        // 1. Dinero recibido cuya venta no se pudo aplicar
        $recibidos = array_map(fn ($e) => $e->value, array_filter(EstadoPago::cases(), fn ($e) => $e->dineroRecibido()));
        foreach (Pago::whereIn('estado', $recibidos)->where('estado_aplicacion', EstadoAplicacion::RequiereRevision->value)->orderBy('id')->get() as $p) {
            $filas[] = ['Recibido sin venta (requiere revisión)', $p->uuid, $metodos[$p->metodo_pago_id] ?? '', $p->monto, $p->estado->value, $horas($p->recibido_at ?? $p->created_at), (string) $p->motivo_revision];
        }

        // 2. Transferencias y QR pendientes de verificación
        foreach (Pago::where('estado', EstadoPago::PendienteVerificacion->value)->orderBy('id')->get() as $p) {
            $filas[] = ['Pendiente de verificación', $p->uuid, $metodos[$p->metodo_pago_id] ?? '', $p->monto, $p->estado->value, $horas($p->created_at), 'Ref. ' . ($p->referencia_externa ?? '—') . ($p->caja_id ? " · caja #{$p->caja_id}" : '')];
        }

        // 3. Pagos en línea con resultado incierto prolongado
        $alerta = (int) config('finance.verificando_alerta_horas', 24);
        foreach (Pago::where('estado', EstadoPago::Verificando->value)->where('updated_at', '<', $ahora->copy()->subHours($alerta))->orderBy('id')->get() as $p) {
            $filas[] = ["Verificando más de {$alerta} h", $p->uuid, $metodos[$p->metodo_pago_id] ?? '', $p->monto, $p->estado->value, $horas($p->updated_at), 'Orden ' . ($p->referencia_externa ?? '—')];
        }

        // 4. Ventas presenciales del período sin pago ligado (anteriores a la Etapa 2 o anómalas)
        $ventas = DB::table('NotaVenta')
            ->whereNotNull('ci_empleado')
            ->whereBetween('fecha', [$desde, $hasta])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pagos')->whereColumn('pagos.nro_factura', 'NotaVenta.nro'))
            ->orderBy('nro')->get();
        foreach ($ventas as $v) {
            $filas[] = ['Venta presencial sin pago ligado', 'Venta N.º ' . $v->nro, $metodos[$v->id_pago] ?? '', Dinero::deDecimal((string) $v->total, 'BOB')->decimal(), '', $horas(\Carbon\Carbon::parse($v->fecha)), 'Empleado ' . $v->ci_empleado];
        }

        return [
            'columnas' => ['Hallazgo', 'Pago / venta', 'Método', 'Importe (BOB)', 'Estado', 'Antigüedad (h)', 'Detalle'],
            'filas' => $filas,
        ];
    }

    private function conciliacionReembolsos(): array
    {
        $ahora = now();
        $horas = fn ($fecha) => $fecha ? (int) floor($fecha->diffInMinutes($ahora, true) / 60) : '';
        $filas = [];

        $consulta = Reembolso::with('pago')
            ->where(function ($q) {
                $q->whereIn('estado', [EstadoReembolso::Autorizado->value, EstadoReembolso::Incierto->value, EstadoReembolso::Procesando->value])
                    ->orWhere(fn ($q2) => $q2->where('estado', EstadoReembolso::Completado->value)->whereNull('medio_devolucion')
                        ->whereHas('pago', fn ($p) => $p->where('proveedor', Pago::PROVEEDOR_MANUAL)));
            })
            ->orderBy('id')->get();

        foreach ($consulta as $r) {
            $hallazgo = match (true) {
                $r->estado === EstadoReembolso::Autorizado => 'Autorizado, falta entregar el dinero',
                $r->estado === EstadoReembolso::Incierto => 'Resultado incierto del proveedor',
                $r->estado === EstadoReembolso::Procesando => 'En proceso en el proveedor',
                default => 'Entregado sin caja ni medio registrados (anterior a la Etapa 3)',
            };
            $filas[] = [$hallazgo, $r->uuid, $r->pago?->uuid ?? '', $r->monto, $r->estado->value, (string) ($r->medio_devolucion ?? ''), $horas($r->autorizado_at ?? $r->created_at), $r->motivo];
        }

        return [
            'columnas' => ['Hallazgo', 'Reembolso', 'Pago', 'Importe (BOB)', 'Estado', 'Medio', 'Antigüedad (h)', 'Motivo'],
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
