<?php

namespace Modules\Finance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Finance\Models\TipoCambio;
use Modules\Finance\Services\TipoCambioService;

/**
 * Tipos de cambio (BOB por 1 USD). Solo el administrador (finance.exchange.manage,
 * aplicado en las rutas).
 */
class TipoCambioController extends Controller
{
    public function index(TipoCambioService $servicio)
    {
        return view('finance::tipos-cambio.index', [
            'tasas' => TipoCambio::withCount('pagos')->with('revisiones')->orderByDesc('fecha_aplicacion')->paginate(30),
            'hoy' => $servicio->hoy()->toDateString(),
            'vigente' => $servicio->vigenteParaHoy(),
        ]);
    }

    public function store(Request $request, TipoCambioService $servicio)
    {
        $request->validate([
            'tasa' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'fecha_aplicacion' => ['required', 'date_format:Y-m-d'],
            'fuente' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $servicio->registrar($request->user(), $request->tasa, $request->fecha_aplicacion, $request->fuente);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tipo de cambio registrado.');
    }

    public function update(Request $request, TipoCambio $tipoCambio, TipoCambioService $servicio)
    {
        $request->validate([
            'tasa' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        try {
            $servicio->modificar($tipoCambio, $request->tasa, $request->motivo, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tipo de cambio modificado (quedó registrada la revisión).');
    }
}
