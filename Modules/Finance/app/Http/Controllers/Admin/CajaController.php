<?php

namespace Modules\Finance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Models\Usuario;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Services\ArqueoService;
use Modules\Finance\Services\CajaService;
use Modules\Finance\Support\Dinero;
use Modules\Sales\Models\Caja;

/**
 * Caja (Etapa 3): apertura y cierre propios (admin, encargado, vendedor),
 * cierre supervisado (admin, encargado), movimientos manuales, historial y
 * corte en PDF. Sustituye al CajaController heredado de Sales (que se
 * conserva sin uso). Conserva los nombres de ruta caja.*.
 */
class CajaController extends Controller
{
    private const REGLA_IMPORTE = ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'];
    private const REGLA_CLAVE = ['required', 'string', 'min:16', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'];

    public function index(Request $request, CajaService $cajas, ArqueoService $arqueo)
    {
        $usuario = $request->user();
        $verTodas = Gate::allows('cash.view_all');

        $request->validate([
            'estado' => ['nullable', 'in:' . Caja::ABIERTA . ',' . Caja::CERRADA],
            'titular' => ['nullable', 'integer'],
        ]);

        $consulta = Caja::with(['user', 'cerradoPor'])->orderByDesc('id');
        if (!$verTodas) {
            $consulta->where('user_id', $usuario->ci);
        } elseif ($request->filled('titular')) {
            $consulta->where('user_id', $request->integer('titular'));
        }
        if ($request->filled('estado')) {
            $consulta->where('estado', $request->estado);
        }

        $miCaja = $cajas->cajaAbiertaDe($usuario);

        return view('finance::caja.index', [
            'cajas' => $consulta->paginate(15)->withQueryString(),
            'miCaja' => $miCaja,
            'miArqueo' => $miCaja ? $arqueo->calcular($miCaja) : null,
            'verTodas' => $verTodas,
            'titulares' => $verTodas ? Usuario::whereIn('ci', Caja::select('user_id')->distinct())->orderBy('nombre')->get() : collect(),
        ]);
    }

    public function show(Request $request, Caja $caja, ArqueoService $arqueo)
    {
        $this->autorizarVer($request, $caja);

        $caja->load(['user', 'cerradoPor', 'eventos.responsable', 'movimientos.registradoPor', 'movimientos.reversion', 'correcciones.caja', 'correcciones.registradoPor']);

        return view('finance::caja.show', [
            'caja' => $caja,
            // Abierta: cálculo en vivo. Cerrada (v2): la foto guardada. Heredada (v1): sin desglose.
            'arqueo' => $caja->estaAbierta() ? $arqueo->calcular($caja)->toArray() : $caja->resumen,
            'pagos' => $caja->pagos()->with('metodoPago')->orderBy('id')->get(),
            'reembolsos' => $caja->reembolsos()->with(['pago', 'entregadoPor'])->orderBy('id')->get(),
            'esPropia' => (string) $caja->user_id === (string) $request->user()->ci,
        ]);
    }

    public function apertura(Request $request, CajaService $cajas)
    {
        Gate::authorize('cash.operate_own');
        $request->validate(['monto_apertura' => self::REGLA_IMPORTE]);

        try {
            $caja = $cajas->abrir($request->user(), Dinero::deDecimal((string) $request->monto_apertura, 'BOB'));
        } catch (\DomainException $e) {
            return redirect()->route('caja.index')->with('error', $e->getMessage());
        }

        return redirect()->route('caja.index')->with('success', "Caja #{$caja->id} abierta con fondo de Bs. {$caja->monto_apertura}.");
    }

    public function corte(Request $request, Caja $caja, CajaService $cajas)
    {
        $esPropia = (string) $caja->user_id === (string) $request->user()->ci;
        Gate::authorize($esPropia ? 'cash.operate_own' : 'cash.close_any');

        $request->validate([
            'efectivo_contado' => self::REGLA_IMPORTE,
            'observaciones' => ['nullable', 'string', 'max:500'],
            'clave_cierre' => self::REGLA_CLAVE,
        ]);

        try {
            $cajas->cerrar(
                $caja,
                $request->user(),
                Dinero::deDecimal((string) $request->efectivo_contado, 'BOB'),
                $request->observaciones,
                'cierre-' . $request->clave_cierre,
            );
        } catch (TransicionInvalida|\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('caja.show', $caja)->with('success', $esPropia ? 'Caja cerrada.' : 'Cierre supervisado registrado.');
    }

    public function movimiento(Request $request, CajaService $cajas)
    {
        $request->validate([
            'tipo' => ['required', 'in:' . MovimientoCaja::INGRESO . ',' . MovimientoCaja::EGRESO],
            'monto' => self::REGLA_IMPORTE,
            'motivo' => ['required', 'string', 'max:255'],
            'clave_idempotencia' => self::REGLA_CLAVE,
            'caja_corregida' => ['nullable', 'integer', 'exists:cajas,id'],
        ]);
        Gate::authorize($request->tipo === MovimientoCaja::INGRESO ? 'cash.movements.income' : 'cash.movements.expense');

        try {
            $cajas->registrarMovimiento(
                $request->user(),
                $request->tipo,
                Dinero::deDecimal((string) $request->monto, 'BOB'),
                (string) $request->motivo,
                'mov-' . $request->clave_idempotencia,
                $request->filled('caja_corregida') ? Caja::findOrFail($request->integer('caja_corregida')) : null,
            );
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', ucfirst($request->tipo) . ' registrado.');
    }

    public function revertir(Request $request, MovimientoCaja $movimiento, CajaService $cajas)
    {
        Gate::authorize('cash.movements.revert');
        $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'clave_idempotencia' => self::REGLA_CLAVE,
        ]);

        try {
            $cajas->revertirMovimiento($movimiento, $request->user(), (string) $request->motivo, 'rev-' . $request->clave_idempotencia);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Movimiento revertido con un movimiento correctivo. El original se conserva.');
    }

    /** Corte en PDF (D8: único reporte en PDF). */
    public function reporte(Request $request, Caja $caja)
    {
        $this->autorizarVer($request, $caja);
        $caja->load(['user', 'cerradoPor']);

        if ($caja->esHeredada()) {
            return $this->reporteHeredado($caja);
        }

        $foto = $caja->estaAbierta() ? app(ArqueoService::class)->calcular($caja)->toArray() : $caja->resumen;

        return Pdf::loadView('finance::caja.corte-pdf', ['caja' => $caja, 'foto' => $foto])
            ->download('corte_caja_' . $caja->id . '.pdf');
    }

    /**
     * Cajas cerradas antes de la Etapa 3: mismo cálculo y vista que el corte
     * heredado, de solo lectura (sus importes no se recalculan ni se guardan).
     */
    private function reporteHeredado(Caja $caja)
    {
        $ventas = DB::table('NotaVenta')
            ->where('ci_empleado', $caja->user_id)
            ->where('fecha', '>=', $caja->fecha_apertura)
            ->where('fecha', '<=', $caja->fecha_cierre ?? now())
            ->sum('total');

        $fondo = $caja->monto_apertura;
        $efectivo_real = $caja->monto_cierre ?? 0;
        $diferencia = (float) ($caja->diferencia ?? 0);
        $faltante = $diferencia < 0 ? abs($diferencia) : 0;
        $sobrante = $diferencia > 0 ? $diferencia : 0;

        return Pdf::loadView('admin.caja.reporte', compact('caja', 'ventas', 'fondo', 'efectivo_real', 'faltante', 'sobrante'))
            ->download('corte_cajero_' . $caja->id . '.pdf');
    }

    private function autorizarVer(Request $request, Caja $caja): void
    {
        if (Gate::allows('cash.view_all')) {
            return;
        }
        if (Gate::allows('cash.operate_own') && (string) $caja->user_id === (string) $request->user()->ci) {
            return;
        }
        abort(403, 'No autorizado');
    }
}
