<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Access\Models\Cliente;
use Modules\Access\Models\Usuario;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\MetodoPago;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\DetalleFacturaVenta;
use Modules\Sales\Models\Caja;
use Modules\Audit\Models\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Exceptions\AplicacionFallida;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Support\Dinero;

class VentaController extends Controller
{
    /**
     * Mostrar la lista de ventas del cajero o general si es administrador.
     */
    public function index()
    {
        $isAdmin = \Illuminate\Support\Facades\Gate::allows('admin');
        
        $query = FacturaVenta::with(['cliente', 'empleado', 'metodoPago']);

        if (!$isAdmin) {
            // Filtrar solo por las ventas del cajero actual
            $query->where('ci_empleado', Auth::user()->ci);
        }

        $ventas = $query->orderBy('fecha', 'desc')->paginate(15);

        return view('ventas.index', compact('ventas', 'isAdmin'));
    }

    /**
     * Formulario de Nueva Venta (Punto de Venta / POS).
     */
    public function create()
    {
        // Métodos de pago activos que se cobran en mostrador
        $metodosPago = MetodoPago::presenciales()->orderBy('id')->get();

        // Obtener productos disponibles en stock
        $productos = Producto::where('cantidad', '>', 0)->get();

        return view('ventas.create', compact('metodosPago', 'productos'));
    }

    /**
     * Buscar cliente por Cédula de Identidad (CI).
     */
    public function buscarCliente($ci)
    {
        $usuario = Usuario::where('ci', $ci)->first();

        if ($usuario) {
            // Si el usuario existe pero no tiene registro en cliente, lo creamos
            if ($usuario->tipoPersona !== 'C' && !$usuario->cliente) {
                Cliente::firstOrCreate([
                    'ci' => $usuario->ci
                ], [
                    'categoria' => null
                ]);
            }

            return response()->json([
                'success' => true,
                'cliente' => [
                    'ci' => $usuario->ci,
                    'nombre' => $usuario->nombre,
                    'apellido' => $usuario->apellido,
                    'telefono' => $usuario->telefono,
                    'email' => $usuario->email
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Cliente no encontrado.'
        ], 404);
    }

    /**
     * Registrar un cliente rápidamente vía AJAX.
     */
    public function registrarCliente(Request $request)
    {
        $request->validate([
            'ci' => 'required|integer|unique:usuario,ci',
            'nombre' => 'required|string|max:50',
            'apellido' => 'required|string|max:50',
            'sexo' => 'required|string|max:1',
            'telefono' => 'nullable|integer',
            'email' => 'required|email|max:150|unique:usuario,email',
            'domicilio' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            // Crear el usuario de tipo Cliente ('C')
            $usuario = Usuario::create([
                'ci' => $request->ci,
                'nombre' => $request->nombre,
                'apellido' => $request->apellido,
                'sexo' => $request->sexo,
                'telefono' => $request->telefono,
                'email' => $request->email,
                'domicilio' => $request->domicilio,
                'tipoPersona' => 'C',
                'password' => Hash::make($request->ci) // Clave por defecto su CI
            ]);

            // Crear el perfil de Cliente
            Cliente::create([
                'ci' => $request->ci,
                'categoria' => 'Regular'
            ]);

            Bitacora::registrar('INSERTAR', 'usuario', $request->ci, "Registro rápido de cliente: {$request->nombre} {$request->apellido}");

            DB::commit();

            return response()->json([
                'success' => true,
                'cliente' => $usuario
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar al cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Almacenar la venta.
     */
    /**
     * Registrar una venta en tienda con su cobro (Fase 2 — Etapa 2).
     *
     * El cobro lo registra el módulo Finance (PagoService::registrarPresencial):
     *  - Efectivo / tarjeta: pago 'recibido' y la venta en la MISMA transacción
     *    (si la venta falla no se registra nada).
     *  - Transferencia / QR: pago 'pendiente_verificacion' SIN venta ni descuento
     *    de stock; la venta se crea cuando un administrador o encargado verifica
     *    el ingreso.
     *  - Referencia obligatoria y única para tarjeta, transferencia y QR.
     *  - `clave_idempotencia`: el mismo envío dos veces no duplica el cobro.
     */
    public function store(Request $request, PagoService $pagos)
    {
        $request->validate([
            'ci_cliente' => 'required|exists:usuario,ci',
            'id_pago' => ['required', MetodoPago::reglaPresencial()],
            'productos' => 'required|array|min:1',
            'productos.*.id' => 'required|distinct|exists:producto,idproducto',
            'productos.*.cantidad' => 'required|integer|min:1',
            'productos.*.descuento' => 'nullable|numeric|min:0',
            'referencia' => 'nullable|string|max:100',
            'efectivo_recibido' => 'nullable|numeric|min:0',
            'clave_idempotencia' => ['required', 'string', 'min:16', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $codigo = MetodoPago::whereKey($request->id_pago)->value('codigo');

        // Precios actuales del servidor (nunca del navegador), congelados en el snapshot
        $items = [];
        $total = Dinero::cero('BOB');
        foreach ($request->productos as $item) {
            $producto = Producto::where('idproducto', $item['id'])->first();
            $precio = Dinero::deDecimal((string) $producto->precio, 'BOB');
            $descuento = Dinero::deDecimal((string) ($item['descuento'] ?? 0), 'BOB');
            $subtotal = Dinero::deCentavos($precio->centavos * (int) $item['cantidad'], 'BOB')->restar($descuento);

            if ($subtotal->centavos < 0) {
                return response()->json(['success' => false, 'message' => 'El descuento no puede ser mayor que el precio total del producto.'], 422);
            }

            $items[] = [
                'idproducto' => (int) $producto->idproducto,
                'nombre' => $producto->nombre,
                'precio_unitario' => $precio->decimal(),
                'cantidad' => (int) $item['cantidad'],
                'descuento' => $descuento->decimal(),
                'subtotal' => $subtotal->decimal(),
            ];
            $total = $total->sumar($subtotal);
        }

        $snapshot = [
            'tipo' => PagoService::TIPO_VENTA_PRESENCIAL,
            'ci_cliente' => (int) $request->ci_cliente,
            'items' => $items,
            'total' => $total->decimal(),
        ];

        try {
            $pago = $pagos->registrarPresencial($request->user(), (string) $codigo, $total, $snapshot, [
                'referencia' => $request->input('referencia'),
                'efectivo_recibido' => $request->input('efectivo_recibido'),
                'clave_idempotencia' => 'pos-' . $request->user()->ci . '-' . $request->input('clave_idempotencia'),
            ]);
        } catch (AplicacionFallida|\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($pago->estado === EstadoPago::PendienteVerificacion) {
            return response()->json([
                'success' => true,
                'pendiente' => true,
                'pago' => $pago->uuid,
                'message' => 'Cobro registrado como PENDIENTE DE VERIFICACIÓN. La venta se confirmará y el stock se descontará cuando un administrador o encargado verifique el ingreso.',
            ]);
        }

        return response()->json([
            'success' => true,
            'pendiente' => false,
            'pago' => $pago->uuid,
            'cambio' => $pago->cambio_entregado !== null ? (string) $pago->cambio_entregado : null,
            'message' => 'Venta registrada con éxito.',
            'nro_factura' => $pago->nro_factura,
        ]);
    }

    /**
     * Generar comprobante en PDF.
     */
    public function imprimir($nro)
    {
        $factura = FacturaVenta::with(['cliente', 'empleado', 'metodoPago', 'detalles.producto'])
            ->where('nro', $nro)
            ->firstOrFail();

        // Solo el personal de ventas o el cliente dueño de la nota puede descargarla
        // (antes cualquier usuario autenticado podía descargar la de otro cliente).
        $esDueno = (string) $factura->ci_cliente === (string) Auth::user()->ci;
        if (!$esDueno && \Illuminate\Support\Facades\Gate::denies('sales.operate')) {
            abort(403, 'No autorizado');
        }

        $pdf = Pdf::loadView('ventas.comprobante', compact('factura'));
        return $pdf->download('factura_venta_' . $factura->nro . '.pdf');
    }
}
