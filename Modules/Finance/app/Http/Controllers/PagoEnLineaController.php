<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Exceptions\PagoNoDisponible;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\PagoService;
use Modules\Sales\Services\CarritoService;

/**
 * Pago en línea del carrito con PayPal (orden y captura EN EL SERVIDOR).
 * El navegador solo aprueba en PayPal; nunca informa el resultado del pago.
 */
class PagoEnLineaController extends Controller
{
    public function crearOrden(Request $request, PagoService $pagos, CarritoService $carrito): JsonResponse
    {
        $request->validate([
            'clave_idempotencia' => ['required', 'string', 'min:16', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $usuario = $request->user();
        $resumen = $carrito->resumen($usuario->ci);

        if ($resumen['items'] === []) {
            return response()->json(['message' => 'Tu carrito está vacío.'], 422);
        }

        $snapshot = [
            'tipo' => PagoService::TIPO_VENTA_EN_LINEA,
            'items' => $resumen['items'],
            'descuentos' => $resumen['descuentos'],
            'subtotal' => $resumen['total']->decimal(),
            'total' => $resumen['total_con_descuento']->decimal(),
        ];

        try {
            $pago = $pagos->iniciarEnLinea(
                $usuario,
                $resumen['total_con_descuento'],
                $snapshot,
                'web-' . $usuario->ci . '-' . $request->input('clave_idempotencia'),
            );
            $pago = $pagos->crearOrden($pago);
        } catch (PagoNoDisponible|TransicionInvalida $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'uuid' => $pago->uuid,
            'order_id' => $pago->referencia_externa,
            'monto_bob' => (string) $pago->monto,
            'monto_proveedor' => (string) $pago->monto_proveedor,
            'moneda_proveedor' => $pago->moneda_proveedor,
            'tasa' => (string) $pago->tasa_aplicada,
        ]);
    }

    public function capturar(Request $request, Pago $pago, PagoService $pagos): JsonResponse
    {
        $this->autorizarDueno($request, $pago);

        try {
            $pago = $pagos->capturar($pago, $request->user());
        } catch (TransicionInvalida $e) {
            return response()->json(['message' => 'El pago no se puede capturar en su estado actual.', 'estado' => $pago->fresh()->estado->value], 409);
        }

        return response()->json($this->estadoPublico($pago));
    }

    public function estado(Request $request, Pago $pago): JsonResponse
    {
        $this->autorizarDueno($request, $pago);

        return response()->json($this->estadoPublico($pago->fresh()));
    }

    public function resultado(Request $request, Pago $pago)
    {
        $this->autorizarDueno($request, $pago);

        return view('finance::pagos.resultado', ['pago' => $pago->fresh(), 'estado' => $this->estadoPublico($pago->fresh())]);
    }

    private function autorizarDueno(Request $request, Pago $pago): void
    {
        if ($pago->canal !== Pago::CANAL_EN_LINEA || (string) $pago->ci_usuario !== (string) $request->user()->ci) {
            abort(403, 'No autorizado');
        }
    }

    private function estadoPublico(Pago $pago): array
    {
        $destino = ($pago->snapshot['tipo'] ?? null) === PagoService::TIPO_RESERVA ? 'reserva' : 'compra';

        $mensaje = match (true) {
            $pago->estado_aplicacion === EstadoAplicacion::Aplicado => $destino === 'reserva' ? 'Pago recibido y registrado en tu reserva.' : 'Pago recibido y compra registrada.',
            $pago->estado->dineroRecibido() => "Pago recibido. Tu {$destino} está en revisión; el personal se comunicará contigo.",
            $pago->estado === EstadoPago::Cancelado && $destino === 'reserva' => 'El pago no se completó porque la reserva ya no admite ese importe. No se realizó ningún cobro.',
            $pago->estado === EstadoPago::Verificando => 'Estamos confirmando tu pago con PayPal. No vuelvas a pagar.',
            $pago->estado === EstadoPago::OrdenCreada => 'Pago pendiente de aprobación en PayPal.',
            in_array($pago->estado, [EstadoPago::Fallido, EstadoPago::Expirado, EstadoPago::Cancelado], true) => 'El pago no se completó. No se realizó ningún cobro.',
            default => 'Estado del pago: ' . $pago->estado->value,
        };

        return [
            'uuid' => $pago->uuid,
            'estado' => $pago->estado->value,
            'estado_aplicacion' => $pago->estado_aplicacion->value,
            'nro_factura' => $pago->nro_factura,
            'reserva_id' => $pago->reserva_id,
            'mensaje' => $mensaje,
            'resultado_url' => route('finanzas.pagos.resultado', $pago),
        ];
    }
}
