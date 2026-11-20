<?php

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Procurement\Models\Devolucion;
use Modules\Procurement\Models\DevolucionDetalle;
use Modules\Sales\Models\FacturaVenta;
use Modules\Inventory\Models\Producto;
use Modules\Audit\Models\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\ReembolsoService;
use Modules\Finance\Support\Dinero;

class DevolucionController extends Controller
{
    /**
     * Listado de devoluciones y garantías
     */
    public function index()
    {
        $devoluciones = Devolucion::with(['factura.cliente', 'empleado', 'detalles.producto'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('admin.devoluciones.index', compact('devoluciones'));
    }

    /**
     * Formulario para registrar nueva devolución/garantía
     */
    public function create()
    {
        return view('admin.devoluciones.create');
    }

    /**
     * Buscar factura por número para asociar la devolución
     */
    public function buscarFactura($nro)
    {
        $factura = FacturaVenta::with(['cliente', 'detalles.producto'])
            ->where('nro', $nro)
            ->first();

        if ($factura) {
            return response()->json([
                'success' => true,
                'factura' => $factura
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Factura no encontrada.'
        ], 404);
    }

    /**
     * Guardar nueva devolución/garantía
     */
    public function store(Request $request)
    {
        $request->validate([
            'nro_factura' => 'required|exists:NotaVenta,nro',
            'tipo' => 'required|in:Devolución,Garantía',
            'motivo' => 'required|string|max:500',
            'productos' => 'required|array|min:1',
            'productos.*.id' => 'required|exists:producto,idproducto',
            'productos.*.cantidad' => 'required|integer|min:1',
        ], [
            'productos.required' => 'Debes seleccionar al menos un producto para la devolución.',
            'nro_factura.exists' => 'La factura indicada no existe en el sistema.',
        ]);

        DB::beginTransaction();
        try {
            $devolucion = Devolucion::create([
                'nro_factura' => $request->nro_factura,
                'tipo' => $request->tipo,
                'motivo' => $request->motivo,
                'fecha' => now(),
                'estado' => 'Pendiente',
                'ci_empleado' => Auth::user()->ci,
                'observaciones' => $request->observaciones,
            ]);

            foreach ($request->productos as $item) {
                DevolucionDetalle::create([
                    'devolucion_id' => $devolucion->id,
                    'idproducto' => $item['id'],
                    'cantidad' => $item['cantidad'],
                ]);
            }

            Bitacora::registrar('INSERTAR', 'devoluciones', $devolucion->id, "Registro de {$request->tipo} para factura Nro. {$request->nro_factura}. Motivo: {$request->motivo}");

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => ucfirst($request->tipo) . ' registrada correctamente.',
                'id' => $devolucion->id,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ver detalle de una devolución
     */
    public function show($id)
    {
        $devolucion = Devolucion::with(['factura.cliente', 'empleado', 'detalles.producto'])
            ->findOrFail($id);

        return view('admin.devoluciones.show', compact('devolucion'));
    }

    /**
     * Aprobar una devolución (Fase 2 — Etapa 2).
     *
     *  - NO modifica la nota de venta ni sus líneas: el historial original se conserva.
     *  - Valida que lo devuelto acumulado no supere lo vendido por línea.
     *  - Guarda el importe neto devuelto (con el descuento de la línea) en la devolución.
     *  - Repone el stock.
     *  - Si es una "Devolución" de una venta cobrada, crea un reembolso VINCULADO
     *    (estado 'solicitado') que el administrador autoriza y ejecuta en Finanzas.
     *    Una "Garantía" no genera reembolso.
     */
    public function aprobar($id, ReembolsoService $reembolsos)
    {
        try {
            $resultado = DB::transaction(function () use ($id) {
                $devolucion = Devolucion::with('detalles')->lockForUpdate()->findOrFail($id);

                if ($devolucion->estado !== 'Pendiente') {
                    throw new \DomainException('Esta devolución ya fue procesada.');
                }

                $lineas = DB::table('detalleNotaVenta')->where('nro_factura', $devolucion->nro_factura)->get()->keyBy('id_producto');

                // Cantidades ya devueltas en devoluciones aprobadas de la misma nota
                $previas = DevolucionDetalle::whereHas('devolucion', fn ($q) => $q
                        ->where('nro_factura', $devolucion->nro_factura)
                        ->where('estado', 'Aprobado'))
                    ->get()
                    ->groupBy('idproducto')
                    ->map(fn ($g) => (int) $g->sum('cantidad'));

                $total = Dinero::cero('BOB');

                foreach ($devolucion->detalles as $detalle) {
                    $linea = $lineas->get($detalle->idproducto);
                    if (!$linea) {
                        throw new \DomainException("El producto {$detalle->idproducto} no figura en la nota {$devolucion->nro_factura}.");
                    }

                    $vendido = (int) $linea->cantidad;
                    $acumulado = (int) ($previas[$detalle->idproducto] ?? 0) + (int) $detalle->cantidad;
                    if ($acumulado > $vendido) {
                        throw new \DomainException("Se intenta devolver {$acumulado} unidades del producto {$detalle->idproducto}, pero solo se vendieron {$vendido}.");
                    }

                    // Neto de la línea en centavos (precio × cantidad − descuento), prorrateado
                    $netoLinea = Dinero::deDecimal((string) $linea->precio_unitario, 'BOB')->centavos * $vendido
                        - Dinero::deDecimal((string) $linea->descuento, 'BOB')->centavos;
                    $subtotalCent = intdiv($netoLinea * (int) $detalle->cantidad * 2 + $vendido, 2 * $vendido); // HALF_UP
                    $unitarioCent = intdiv($netoLinea * 2 + $vendido, 2 * $vendido);

                    $detalle->update([
                        'precio_unitario' => Dinero::deCentavos($unitarioCent, 'BOB')->decimal(),
                        'subtotal' => Dinero::deCentavos($subtotalCent, 'BOB')->decimal(),
                    ]);

                    $producto = Producto::where('idproducto', $detalle->idproducto)->lockForUpdate()->first();
                    if ($producto) {
                        $producto->cantidad += $detalle->cantidad;
                        $producto->save();
                    }

                    $total = $total->sumar(Dinero::deCentavos($subtotalCent, 'BOB'));
                }

                $devolucion->update([
                    'estado' => 'Aprobado',
                    'monto_total' => $devolucion->tipo === 'Devolución' ? $total->decimal() : '0.00',
                    'ci_aprobado_por' => Auth::user()->ci,
                    'aprobado_at' => now(),
                ]);

                Bitacora::registrar('ACTUALIZAR', 'devoluciones', $devolucion->id, "{$devolucion->tipo} Nro. {$devolucion->id} APROBADA por {$total->decimal()} BOB. Stock repuesto; la nota {$devolucion->nro_factura} no se modifica.");

                return ['devolucion' => $devolucion, 'total' => $total];
            });
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Error al aprobar la devolución.');
        }

        $devolucion = $resultado['devolucion'];
        $mensaje = 'Devolución aprobada. El stock se repuso; la nota de venta original se conserva sin cambios.';

        if ($devolucion->tipo === 'Devolución' && $resultado['total']->esPositivo()) {
            $mensaje .= ' ' . $this->solicitarReembolso($devolucion, $resultado['total'], $reembolsos);
        }

        return back()->with('success', $mensaje);
    }

    /**
     * Crea el/los reembolso(s) vinculados a la devolución sobre los pagos recibidos de la venta.
     */
    private function solicitarReembolso(Devolucion $devolucion, Dinero $total, ReembolsoService $reembolsos): string
    {
        $pagos = Pago::where('nro_factura', $devolucion->nro_factura)
            ->whereIn('estado', [EstadoPago::Recibido->value, EstadoPago::ReembolsadoParcial->value])
            ->orderBy('id')->get();

        if ($pagos->isEmpty()) {
            Bitacora::registrar('REEMBOLSO_SIN_PAGO', 'devoluciones', $devolucion->id, "La nota {$devolucion->nro_factura} no tiene pagos registrados en Finanzas; el reembolso de {$total->decimal()} BOB debe gestionarse manualmente.");
            return "La venta no tiene un pago registrado en Finanzas: gestione el reembolso de {$total->decimal()} BOB manualmente.";
        }

        $pendiente = $total;
        try {
            foreach ($pagos as $pago) {
                if (!$pendiente->esPositivo()) {
                    break;
                }
                $disponible = $reembolsos->disponible($pago);
                if (!$disponible->esPositivo()) {
                    continue;
                }
                $monto = $pendiente->mayorQue($disponible) ? $disponible : $pendiente;
                $reembolsos->solicitar($pago, $monto, "Devolución Nro. {$devolucion->id}: {$devolucion->motivo}", Auth::user(), "devolucion-{$devolucion->id}-{$pago->id}", $devolucion->id);
                $pendiente = $pendiente->restar($monto);
            }
        } catch (\DomainException $e) {
            return 'No se pudo crear el reembolso: ' . $e->getMessage();
        }

        return $pendiente->esPositivo()
            ? "Se solicitó un reembolso parcial; quedan {$pendiente->decimal()} BOB sin cubrir por pagos registrados."
            : "Se solicitó el reembolso de {$total->decimal()} BOB; debe autorizarlo un administrador en Finanzas.";
    }

    /**
     * Rechazar una devolución
     */
    public function rechazar($id)
    {
        $devolucion = Devolucion::findOrFail($id);

        if ($devolucion->estado !== 'Pendiente') {
            return back()->with('error', 'Esta devolución ya fue procesada.');
        }

        $devolucion->estado = 'Rechazado';
        $devolucion->save();

        Bitacora::registrar('ACTUALIZAR', 'devoluciones', $devolucion->id, "Devolución/Garantía Nro. {$devolucion->id} RECHAZADA.");

        return back()->with('success', 'Devolución rechazada correctamente.');
    }
}
