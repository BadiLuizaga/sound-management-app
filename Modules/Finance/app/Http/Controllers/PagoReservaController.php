<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Exceptions\PagoNoDisponible;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Services\PagoReservaService;
use Modules\Finance\Support\Dinero;
use Modules\Rentals\Models\Reserva;
use Modules\Sales\Models\MetodoPago;

/**
 * Pagos de reservas (Etapa 4 — paso 4). Solo JSON: las pantallas del ERP, del
 * POS y del portal son del paso 7 y llamarán a estos mismos puntos.
 *
 *  - estadoCuenta: el titular o el personal con acceso a reservas.
 *  - cobrarEnCaja: personal con finance.payments.register y caja abierta.
 *  - pagarEnLinea: solo el titular; crea la orden de PayPal en el servidor.
 *    La captura, el estado y el resultado usan las rutas de pago existentes.
 */
class PagoReservaController extends Controller
{
    private const MONTO = ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'];
    private const MONTO_OPCIONAL = ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'];
    private const CLAVE = ['required', 'string', 'min:16', 'max:50', 'regex:/^[A-Za-z0-9-]+$/'];

    public function estadoCuenta(Request $request, Reserva $reserva, PagoReservaService $servicio): JsonResponse
    {
        $esTitular = (string) $reserva->ci_cliente === (string) $request->user()->ci;
        if (!$esTitular && !Gate::any(['rentals.view', 'finance.payments.view_all'])) {
            abort(403, 'No autorizado');
        }

        return response()->json($servicio->estadoCuenta($reserva)->toArray());
    }

    public function cobrarEnCaja(Request $request, Reserva $reserva, PagoReservaService $servicio): JsonResponse
    {
        Gate::authorize('finance.payments.register');

        $request->validate([
            'id_pago' => ['required', MetodoPago::reglaPresencial()],
            'monto' => self::MONTO,
            'efectivo_recibido' => self::MONTO_OPCIONAL,
            'referencia' => ['nullable', 'string', 'max:100'],
            'clave_idempotencia' => self::CLAVE,
        ]);

        $codigo = (string) MetodoPago::whereKey($request->id_pago)->value('codigo');

        try {
            $pago = $servicio->cobrarEnCaja($request->user(), $reserva, $codigo, Dinero::deDecimal($request->monto, 'BOB'), [
                'clave_idempotencia' => $request->clave_idempotencia,
                'referencia' => $request->input('referencia'),
                'efectivo_recibido' => $request->input('efectivo_recibido'),
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'pago' => $pago->uuid,
            'estado' => $pago->estado->value,
            'estado_aplicacion' => $pago->estado_aplicacion->value,
            'monto' => (string) $pago->monto,
            'cambio' => $pago->cambio_entregado !== null ? (string) $pago->cambio_entregado : null,
            'tipo_cobro' => $pago->snapshot['tipo_cobro'] ?? null,
            'cuenta' => $servicio->estadoCuenta($reserva)->toArray(),
        ], $pago->wasRecentlyCreated ? 201 : 200);
    }

    public function pagarEnLinea(Request $request, Reserva $reserva, PagoReservaService $servicio): JsonResponse
    {
        $request->validate([
            'monto' => self::MONTO_OPCIONAL,
            'clave_idempotencia' => self::CLAVE,
        ]);

        $monto = $request->filled('monto') ? Dinero::deDecimal($request->monto, 'BOB') : null;

        try {
            $pago = $servicio->pagarEnLinea($request->user(), $reserva, $monto, $request->clave_idempotencia);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PagoNoDisponible|TransicionInvalida|\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'uuid' => $pago->uuid,
            'order_id' => $pago->referencia_externa,
            'monto_bob' => (string) $pago->monto,
            'monto_proveedor' => (string) $pago->monto_proveedor,
            'moneda_proveedor' => $pago->moneda_proveedor,
            'tasa' => (string) $pago->tasa_aplicada,
            'tipo_cobro' => $pago->snapshot['tipo_cobro'] ?? null,
            'captura_url' => route('finanzas.paypal.captura', $pago),
            'resultado_url' => route('finanzas.pagos.resultado', $pago),
        ]);
    }
}
