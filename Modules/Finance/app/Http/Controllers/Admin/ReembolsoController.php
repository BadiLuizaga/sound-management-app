<?php

namespace Modules\Finance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Services\ReembolsoService;
use Modules\Finance\Support\Dinero;

/**
 * Reembolsos: el encargado solicita; el administrador autoriza y ejecuta
 * (o hace el proceso completo). En caja, el personal confirma la entrega.
 */
class ReembolsoController extends Controller
{
    public function solicitar(Request $request, Pago $pago, ReembolsoService $reembolsos)
    {
        Gate::authorize('finance.refunds.request');
        $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'motivo' => 'required|string|max:255',
            'clave_idempotencia' => ['required', 'string', 'min:16', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            'ejecutar' => 'nullable|boolean',
        ]);

        try {
            $reembolso = $reembolsos->solicitar(
                $pago,
                Dinero::deDecimal((string) $request->monto, 'BOB'),
                $request->motivo,
                $request->user(),
                'admin-' . $request->clave_idempotencia,
            );

            // El administrador puede realizar el proceso completo en un paso
            if ($request->boolean('ejecutar') && Gate::allows('finance.refunds.execute')) {
                $reembolso = $reembolsos->autorizarYEjecutar($reembolso, $request->user());
            }
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reembolso ' . $reembolso->estado->value . ' por ' . $reembolso->monto . ' BOB.');
    }

    public function autorizar(Request $request, Reembolso $reembolso, ReembolsoService $reembolsos)
    {
        Gate::authorize('finance.refunds.execute');

        try {
            $reembolso = $reembolsos->autorizarYEjecutar($reembolso, $request->user());
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $mensaje = match ($reembolso->estado) {
            EstadoReembolso::Completado => 'Reembolso completado.',
            EstadoReembolso::Autorizado => 'Reembolso autorizado. Falta confirmar la entrega del dinero en caja.',
            EstadoReembolso::Incierto, EstadoReembolso::Procesando => 'PayPal está procesando el reembolso; se confirmará automáticamente.',
            default => 'Reembolso ' . $reembolso->estado->value . '.',
        };

        return back()->with($reembolso->estado === EstadoReembolso::Fallido ? 'error' : 'success', $mensaje);
    }

    public function rechazar(Request $request, Reembolso $reembolso, ReembolsoService $reembolsos)
    {
        Gate::authorize('finance.refunds.execute');
        $request->validate(['motivo' => 'required|string|max:255']);

        try {
            $reembolsos->rechazar($reembolso, $request->user(), $request->motivo);
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reembolso rechazado.');
    }

    public function confirmarEntrega(Request $request, Reembolso $reembolso, ReembolsoService $reembolsos)
    {
        Gate::authorize('finance.refunds.request');

        try {
            $reembolsos->confirmarEntrega($reembolso, $request->user());
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Entrega del reembolso confirmada.');
    }
}
