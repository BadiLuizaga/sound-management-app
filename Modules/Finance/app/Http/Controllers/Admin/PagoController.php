<?php

namespace Modules\Finance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Services\ReembolsoService;
use Modules\Sales\Models\MetodoPago;

/**
 * Administración de pagos: listado, detalle, verificación de transferencias/QR
 * y conciliación. El vendedor solo ve los pagos de caja que registró él.
 */
class PagoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $verTodos = Gate::allows('finance.payments.view_all');
        if (!$verTodos && !Gate::allows('finance.payments.view_own')) {
            abort(403, 'No autorizado');
        }

        $consulta = Pago::with(['metodoPago', 'registradoPor', 'pagador'])->orderByDesc('id');

        if (!$verTodos) {
            $consulta->where('ci_registrado_por', $user->ci);
        }

        $request->validate([
            'estado' => ['nullable', 'in:' . implode(',', array_column(EstadoPago::cases(), 'value'))],
            'aplicacion' => ['nullable', 'in:' . implode(',', array_column(EstadoAplicacion::cases(), 'value'))],
            'metodo' => ['nullable', 'integer'],
        ]);

        if ($request->filled('estado')) {
            $consulta->where('estado', $request->estado);
        }
        if ($request->filled('aplicacion')) {
            $consulta->where('estado_aplicacion', $request->aplicacion);
        }
        if ($request->filled('metodo')) {
            $consulta->where('metodo_pago_id', $request->metodo);
        }

        return view('finance::pagos.index', [
            'pagos' => $consulta->paginate(25)->withQueryString(),
            'metodos' => MetodoPago::orderBy('id')->get(),
            'estados' => EstadoPago::cases(),
            'aplicaciones' => EstadoAplicacion::cases(),
            'verTodos' => $verTodos,
        ]);
    }

    public function show(Request $request, Pago $pago, ReembolsoService $reembolsos)
    {
        $this->autorizarVer($request, $pago);

        return view('finance::pagos.show', [
            'pago' => $pago->load(['metodoPago', 'registradoPor', 'verificadoPor', 'pagador', 'eventos', 'reembolsos', 'tipoCambio']),
            'disponible' => $reembolsos->disponible($pago),
            'medioPorDefecto' => $reembolsos->medioPorDefecto($pago),
        ]);
    }

    public function verificar(Request $request, Pago $pago, PagoService $pagos)
    {
        Gate::authorize('finance.payments.verify');

        try {
            $pago = $pagos->verificar($pago, $request->user());
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $pago->estado_aplicacion === EstadoAplicacion::Aplicado
            ? "Ingreso verificado. Venta N.º {$pago->nro_factura} registrada."
            : 'Ingreso verificado. La venta quedó en revisión: ' . $pago->motivo_revision);
    }

    public function rechazar(Request $request, Pago $pago, PagoService $pagos)
    {
        Gate::authorize('finance.payments.verify');
        $request->validate(['motivo' => 'required|string|max:255']);

        try {
            $pagos->rechazar($pago, $request->user(), $request->motivo);
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pago rechazado. No se registró ninguna venta.');
    }

    public function reintentar(Request $request, Pago $pago, PagoService $pagos)
    {
        Gate::authorize('finance.payments.reconcile');

        $pago = $pagos->reintentarAplicacion($pago, $request->user());

        return back()->with($pago->estado_aplicacion->aplicado() ? 'success' : 'error',
            $pago->estado_aplicacion->aplicado() ? 'Pago aplicado correctamente.' : 'No se pudo aplicar: ' . $pago->motivo_revision);
    }

    public function aplicarManual(Request $request, Pago $pago, PagoService $pagos)
    {
        Gate::authorize('finance.payments.reconcile');
        $request->validate(['motivo' => 'required|string|max:255']);

        try {
            $pagos->aplicarManual($pago, $request->user(), $request->motivo);
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pago marcado como aplicado manualmente.');
    }

    private function autorizarVer(Request $request, Pago $pago): void
    {
        if (Gate::allows('finance.payments.view_all')) {
            return;
        }
        if (Gate::allows('finance.payments.view_own') && (string) $pago->ci_registrado_por === (string) $request->user()->ci) {
            return;
        }
        abort(403, 'No autorizado');
    }
}
