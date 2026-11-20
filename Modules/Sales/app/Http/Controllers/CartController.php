<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\Carrito;
use Modules\Sales\Models\Promocion;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\DetalleFacturaVenta;
use Modules\Audit\Models\Bitacora;
use Modules\Access\Models\Cliente;
use Modules\Sales\Models\Cotizacion;
use Modules\Sales\Models\CotizacionDetalle;
use Modules\Sales\Models\PagoExterno;
use Modules\Sales\Models\MetodoPago;
use Modules\Sales\Services\PayPalOrderVerifier;

class CartController extends Controller
{
    private function mergeSessionCart()
    {
        if (Auth::check() && session()->has('carrito')) {
            $sessionCart = session()->get('carrito');
            $ci = Auth::user()->ci;

            foreach ($sessionCart as $idproducto => $item) {
                $dbItem = Carrito::where('ci_usuario', $ci)->where('idproducto', $idproducto)->first();
                if ($dbItem) {
                    $dbItem->cantidad += $item['cantidad'];
                    $dbItem->save();
                } else {
                    Carrito::create([
                        'ci_usuario' => $ci,
                        'idproducto' => $idproducto,
                        'cantidad' => $item['cantidad']
                    ]);
                }
            }
            session()->forget('carrito');
        }
    }

    public function index()
    {
        $this->mergeSessionCart();

        $cartItems = [];
        $total = 0;

        if (Auth::check()) {
            $items = Carrito::with('producto')->where('ci_usuario', Auth::user()->ci)->get();
            foreach ($items as $item) {
                if ($item->producto) {
                    $subtotal = $item->producto->precio * $item->cantidad;
                    $cartItems[] = [
                        'idproducto' => $item->idproducto,
                        'nombre' => $item->producto->nombre,
                        'precio' => $item->producto->precio,
                        'cantidad' => $item->cantidad,
                        'subtotal' => $subtotal
                    ];
                    $total += $subtotal;
                }
            }
        } else {
            $sessionCart = session()->get('carrito', []);
            foreach ($sessionCart as $idproducto => $item) {
                $producto = Producto::find($idproducto);
                if ($producto) {
                    $subtotal = $producto->precio * $item['cantidad'];
                    $cartItems[] = [
                        'idproducto' => $idproducto,
                        'nombre' => $producto->nombre,
                        'precio' => $producto->precio,
                        'cantidad' => $item['cantidad'],
                        'subtotal' => $subtotal
                    ];
                    $total += $subtotal;
                }
            }
        }

        // Calcular descuentos de promociones activas
        $discounts = $this->getCartDiscounts($cartItems);
        $totalDiscount = collect($discounts)->sum('monto');
        $totalConDescuento = max(0, $total - $totalDiscount);

        // El botón de pago en línea solo se ofrece si el método PAYPAL existe y está activo
        $pagoEnLineaDisponible = MetodoPago::disponible(MetodoPago::PAYPAL);

        return view('carrito.index', compact('cartItems', 'total', 'discounts', 'totalDiscount', 'totalConDescuento', 'pagoEnLineaDisponible'));
    }

    public function add(Request $request)
    {
        $idproducto = $request->input('idproducto');
        $cantidad = max(1, (int)$request->input('cantidad', 1));
        
        $producto = Producto::findOrFail($idproducto);

        // Check if adding this quantity exceeds available stock
        $currentCartQuantity = 0;
        if (Auth::check()) {
            $ci = Auth::user()->ci;
            $dbItem = Carrito::where('ci_usuario', $ci)->where('idproducto', $idproducto)->first();
            if ($dbItem) $currentCartQuantity = $dbItem->cantidad;
        } else {
            $cart = session()->get('carrito', []);
            if (isset($cart[$idproducto])) $currentCartQuantity = $cart[$idproducto]['cantidad'];
        }

        if ($currentCartQuantity + $cantidad > $producto->cantidad) {
            $availableToAdd = max(0, $producto->cantidad - $currentCartQuantity);
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock insuficiente. Solo puedes añadir ' . $availableToAdd . ' unidad(es) más.'
                ]);
            }
            return redirect()->back()->with('error', 'Stock insuficiente.');
        }

        if (Auth::check()) {
            $ci = Auth::user()->ci;
            $dbItem = Carrito::where('ci_usuario', $ci)->where('idproducto', $idproducto)->first();
            
            if ($dbItem) {
                $dbItem->cantidad += $cantidad;
                $dbItem->save();
            } else {
                Carrito::create([
                    'ci_usuario' => $ci,
                    'idproducto' => $idproducto,
                    'cantidad' => $cantidad
                ]);
            }
        } else {
            $cart = session()->get('carrito', []);
            if (isset($cart[$idproducto])) {
                $cart[$idproducto]['cantidad'] += $cantidad;
            } else {
                $cart[$idproducto] = [
                    'cantidad' => $cantidad
                ];
            }
            session()->put('carrito', $cart);
        }

        if ($request->ajax()) {
            $cartCount = Auth::check() 
                ? Carrito::where('ci_usuario', Auth::user()->ci)->sum('cantidad')
                : collect(session()->get('carrito', []))->sum('cantidad');
                
            return response()->json([
                'success' => true, 
                'cartCount' => $cartCount
            ]);
        }

        return redirect()->back()->with('success', 'Producto agregado al carrito.');
    }

    public function update(Request $request)
    {
        $idproducto = $request->input('idproducto');
        $cantidad = max(1, (int)$request->input('cantidad', 1));

        $producto = Producto::findOrFail($idproducto);

        // Check if requested quantity exceeds available stock
        if ($cantidad > $producto->cantidad) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock insuficiente. Solo hay ' . $producto->cantidad . ' unidad(es) disponibles.',
                    'revertTo' => $producto->cantidad // Opcional, para revertir el input si quisiéramos
                ]);
            }
            return redirect()->back()->with('error', 'Stock insuficiente.');
        }

        if (Auth::check()) {
            $ci = Auth::user()->ci;
            $dbItem = Carrito::where('ci_usuario', $ci)->where('idproducto', $idproducto)->first();
            if ($dbItem) {
                $dbItem->cantidad = $cantidad;
                $dbItem->save();
            }
        } else {
            $cart = session()->get('carrito', []);
            if (isset($cart[$idproducto])) {
                $cart[$idproducto]['cantidad'] = $cantidad;
                session()->put('carrito', $cart);
            }
        }

        if ($request->ajax()) {
            $cartCount = 0;
            $total = 0;
            $subtotal = 0;

            if (Auth::check()) {
                $cartCount = Carrito::where('ci_usuario', Auth::user()->ci)->sum('cantidad');
                $items = Carrito::with('producto')->where('ci_usuario', Auth::user()->ci)->get();
                foreach ($items as $item) {
                    if ($item->producto) {
                        $itemSub = $item->producto->precio * $item->cantidad;
                        $total += $itemSub;
                        if ($item->idproducto == $idproducto) $subtotal = $itemSub;
                    }
                }
            } else {
                $cart = session()->get('carrito', []);
                foreach ($cart as $id => $item) {
                    $cartCount += $item['cantidad'];
                    $prod = Producto::find($id);
                    if ($prod) {
                        $itemSub = $prod->precio * $item['cantidad'];
                        $total += $itemSub;
                        if ($id == $idproducto) $subtotal = $itemSub;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'cartCount' => $cartCount,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'total' => number_format($total, 2, '.', '')
            ]);
        }

        return redirect()->route('carrito.index')->with('success', 'Carrito actualizado.');
    }

    public function remove(Request $request)
    {
        $idproducto = $request->input('idproducto');

        if (Auth::check()) {
            Carrito::where('ci_usuario', Auth::user()->ci)->where('idproducto', $idproducto)->delete();
        } else {
            $cart = session()->get('carrito', []);
            if (isset($cart[$idproducto])) {
                unset($cart[$idproducto]);
                session()->put('carrito', $cart);
            }
        }

        if ($request->ajax()) {
            $cartCount = 0;
            $total = 0;

            if (Auth::check()) {
                $cartCount = Carrito::where('ci_usuario', Auth::user()->ci)->sum('cantidad');
                $items = Carrito::with('producto')->where('ci_usuario', Auth::user()->ci)->get();
                foreach ($items as $item) {
                    if ($item->producto) {
                        $total += $item->producto->precio * $item->cantidad;
                    }
                }
            } else {
                $cart = session()->get('carrito', []);
                foreach ($cart as $id => $item) {
                    $cartCount += $item['cantidad'];
                    $prod = Producto::find($id);
                    if ($prod) {
                        $total += $prod->precio * $item['cantidad'];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'cartCount' => $cartCount,
                'total' => number_format($total, 2, '.', '')
            ]);
        }

        return redirect()->route('carrito.index')->with('success', 'Producto eliminado del carrito.');
    }

    public function clear()
    {
        if (Auth::check()) {
            Carrito::where('ci_usuario', Auth::user()->ci)->delete();
        } else {
            session()->forget('carrito');
        }

        return redirect()->route('carrito.index')->with('success', 'Carrito vaciado.');
    }

    /**
     * Calcula el total del carrito (con descuentos de promociones) a partir
     * de los precios actuales en BD.
     */
    private function calcularTotalCarrito($items): float
    {
        $cartItems = [];
        $total = 0;

        foreach ($items as $item) {
            if ($item->producto) {
                $subtotal = $item->producto->precio * $item->cantidad;
                $cartItems[] = [
                    'idproducto' => $item->idproducto,
                    'nombre' => $item->producto->nombre,
                    'precio' => $item->producto->precio,
                    'cantidad' => $item->cantidad,
                    'subtotal' => $subtotal
                ];
                $total += $subtotal;
            }
        }

        $discounts = $this->getCartDiscounts($cartItems);
        $totalDiscount = collect($discounts)->sum('monto');

        return round(max(0, $total - $totalDiscount), 2);
    }

    /**
     * Checkout en línea.
     *
     * Fase 1 (seguridad): antes bastaba con enviar este formulario para que
     * se registrara una venta "pagada" y se descontara stock, sin ningún pago.
     * Ahora:
     *  1. El navegador solo envía el ID de la orden de PayPal.
     *  2. El servidor consulta la orden en PayPal y comprueba estado COMPLETED,
     *     moneda y monto exacto del carrito.
     *  3. La orden se registra en `pagos_externos` con clave única: una misma
     *     orden no puede generar dos ventas.
     *  4. Si el pago es válido pero la venta falla (p. ej. sin stock), el pago
     *     queda en estado 'requiere_reembolso' para revisión del personal.
     */
    public function checkout(Request $request, PayPalOrderVerifier $verifier)
    {
        $this->mergeSessionCart();

        if (!$verifier->isConfigured()) {
            return redirect()->route('carrito.index')->with('error', 'El pago en línea no está disponible en este momento.');
        }

        // Método de pago por código (nunca un ID fijo). Si no existe o está
        // desactivado, no se registra ninguna venta.
        try {
            $idPagoEnLinea = MetodoPago::idPorCodigo(MetodoPago::PAYPAL);
        } catch (\DomainException $e) {
            return redirect()->route('carrito.index')->with('error', 'El pago en línea no está disponible en este momento.');
        }

        $request->validate([
            'paypal_order_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);
        $orderId = $request->input('paypal_order_id');
        $ci = Auth::user()->ci;

        // Verificar que hay items
        $items = Carrito::with('producto')->where('ci_usuario', $ci)->get();
        if ($items->isEmpty()) {
            return redirect()->route('carrito.index')->with('error', 'Tu carrito está vacío.');
        }

        if (PagoExterno::where('proveedor', 'paypal')->where('referencia_externa', $orderId)->exists()) {
            return redirect()->route('carrito.index')->with('error', 'Esta orden de pago ya fue utilizada.');
        }

        // Verificación en el servidor (fuera de la transacción: llamada de red)
        try {
            $pago = $verifier->verify($orderId, $this->calcularTotalCarrito($items));
        } catch (\RuntimeException $e) {
            Bitacora::registrar('PAGO_RECHAZADO', 'pagos_externos', 0, "Orden PayPal {$orderId} rechazada para CI {$ci}: " . $e->getMessage());
            return redirect()->route('carrito.index')->with('error', 'No se pudo verificar el pago: ' . $e->getMessage());
        }

        DB::beginTransaction();

        try {
            // Bloquear los productos y recalcular con precios bloqueados
            $ids = $items->pluck('idproducto')->all();
            Producto::whereIn('idproducto', $ids)->lockForUpdate()->get();
            $items = Carrito::with('producto')->where('ci_usuario', $ci)->get();
            $totalConDescuento = $this->calcularTotalCarrito($items);

            if ((int) round($totalConDescuento * 100) !== (int) round(((float) $pago['amount']) * 100)) {
                throw new \RuntimeException('El total del carrito cambió durante el pago.');
            }

            // Asegurar que el usuario tenga un registro en la tabla 'cliente'
            Cliente::firstOrCreate(['ci' => $ci], [
                'categoria' => 'Regular'
            ]);

            // Obtener el siguiente número correlativo para la factura (FOR UPDATE)
            $ultimoNro = FacturaVenta::lockForUpdate()->max('nro') ?? 0;
            $nroFactura = $ultimoNro + 1;

            // Idempotencia: la clave única impide reutilizar la misma orden
            PagoExterno::create([
                'proveedor' => 'paypal',
                'referencia_externa' => $pago['order_id'],
                'referencia_captura' => $pago['capture_id'],
                'monto' => $pago['amount'],
                'moneda' => $pago['currency'],
                'estado' => 'aplicado',
                'ci_usuario' => $ci,
                'nro_factura' => $nroFactura,
            ]);

            // Crear la Factura de Venta (NotaVenta)
            $factura = FacturaVenta::create([
                'nro' => $nroFactura,
                'fecha' => now(),
                'total' => $totalConDescuento,
                'ci_cliente' => $ci,
                'ci_empleado' => null, // Compra online realizada por el cliente
                'id_pago' => $idPagoEnLinea // método PAYPAL, resuelto por código
            ]);

            // Descontar la cantidad de stock en cada producto y crear detalles
            foreach ($items as $item) {
                $producto = Producto::where('idproducto', $item->idproducto)->lockForUpdate()->first();
                if ($producto) {
                    if ($producto->cantidad < $item->cantidad) {
                        throw new \Exception("Stock insuficiente para el producto: {$producto->nombre}.");
                    }
                    // Previene que quede en negativo
                    $producto->cantidad -= $item->cantidad;
                    $producto->save();

                    // Crear detalle de venta
                    DetalleFacturaVenta::create([
                        'nro_factura' => $nroFactura,
                        'id_producto' => $item->idproducto,
                        'precio_unitario' => $producto->precio,
                        'cantidad' => $item->cantidad,
                        'descuento' => 0.00
                    ]);
                }
            }

            // Vaciar el carrito ya que la compra fue "hecha"
            Carrito::where('ci_usuario', Auth::user()->ci)->delete();

            // Registrar en la Bitácora
            Bitacora::registrar(
                'INSERTAR',
                'notaventa',
                $nroFactura,
                "Compra online realizada con éxito por total de " . number_format($totalConDescuento, 2) . " BOB. Cliente CI: " . Auth::user()->ci
                . ". Orden PayPal {$pago['order_id']} / captura {$pago['capture_id']}"
            );

            DB::commit();

            return view('carrito.success');

        } catch (\Throwable $e) {
            DB::rollBack();

            // Doble envío simultáneo: otra petición ya aplicó esta misma orden.
            $yaAplicada = PagoExterno::where('proveedor', 'paypal')
                ->where('referencia_externa', $pago['order_id'])
                ->where('estado', 'aplicado')
                ->exists();
            if ($yaAplicada) {
                return redirect()->route('carrito.index')->with('success', 'Tu pago ya fue procesado.');
            }

            // El dinero ya fue cobrado: dejar constancia para reembolso manual.
            try {
                PagoExterno::firstOrCreate(
                    ['proveedor' => 'paypal', 'referencia_externa' => $pago['order_id']],
                    [
                        'referencia_captura' => $pago['capture_id'],
                        'monto' => $pago['amount'],
                        'moneda' => $pago['currency'],
                        'estado' => 'requiere_reembolso',
                        'ci_usuario' => $ci,
                    ]
                );
                Bitacora::registrar('PAGO_SIN_VENTA', 'pagos_externos', 0, "Orden PayPal {$pago['order_id']} cobrada pero la venta falló (CI {$ci}). Requiere reembolso.");
            } catch (\Throwable $ignored) {
                \Illuminate\Support\Facades\Log::critical('Pago PayPal cobrado sin venta ni registro', ['order' => $pago['order_id'], 'ci' => $ci]);
            }

            $mensaje = $e instanceof \RuntimeException || str_starts_with($e->getMessage(), 'Stock insuficiente')
                ? $e->getMessage()
                : 'Error al procesar la compra.';

            return redirect()->route('carrito.index')->with('error', $mensaje . ' Tu pago quedó registrado y el personal se comunicará contigo para el reembolso.');
        }
    }

    public function generarCotizacion()
    {
        $this->mergeSessionCart();

        $cartItems = [];
        $total = 0;

        if (Auth::check()) {
            $items = Carrito::with('producto')->where('ci_usuario', Auth::user()->ci)->get();
            foreach ($items as $item) {
                if ($item->producto) {
                    $subtotal = $item->producto->precio * $item->cantidad;
                    $cartItems[] = [
                        'idproducto' => $item->idproducto,
                        'nombre' => $item->producto->nombre,
                        'precio' => $item->producto->precio,
                        'cantidad' => $item->cantidad,
                        'subtotal' => $subtotal
                    ];
                    $total += $subtotal;
                }
            }
        } else {
            $sessionCart = session()->get('carrito', []);
            foreach ($sessionCart as $idproducto => $item) {
                $producto = Producto::find($idproducto);
                if ($producto) {
                    $subtotal = $producto->precio * $item['cantidad'];
                    $cartItems[] = [
                        'idproducto' => $idproducto,
                        'nombre' => $producto->nombre,
                        'precio' => $producto->precio,
                        'cantidad' => $item['cantidad'],
                        'subtotal' => $subtotal
                    ];
                    $total += $subtotal;
                }
            }
        }

        if (empty($cartItems)) {
            return redirect()->route('carrito.index')->with('error', 'Tu carrito está vacío, no se puede generar la cotización.');
        }

        // Calcular descuentos de promociones activas
        $discounts = $this->getCartDiscounts($cartItems);
        $totalDiscount = collect($discounts)->sum('monto');
        $totalConDescuento = max(0, $total - $totalDiscount);

        return view('cotizacion.imprimir', compact('cartItems', 'total', 'discounts', 'totalDiscount', 'totalConDescuento'));
    }

    /**
     * Guardar el carrito actual como una cotización persistente.
     */
    public function guardarCotizacion(Request $request)
    {
        $this->mergeSessionCart();

        $ci = Auth::user()->ci;
        $items = Carrito::with('producto')->where('ci_usuario', $ci)->get();

        if ($items->isEmpty()) {
            return redirect()->route('carrito.index')->with('error', 'Tu carrito está vacío. No se puede guardar una cotización.');
        }

        // Calcular items y total
        $cartItems = [];
        $total = 0;

        foreach ($items as $item) {
            if ($item->producto) {
                $subtotal = $item->producto->precio * $item->cantidad;
                $cartItems[] = [
                    'idproducto' => $item->idproducto,
                    'nombre' => $item->producto->nombre,
                    'precio' => $item->producto->precio,
                    'cantidad' => $item->cantidad,
                    'subtotal' => $subtotal
                ];
                $total += $subtotal;
            }
        }

        // Aplicar descuentos de promociones activas
        $discounts = $this->getCartDiscounts($cartItems);
        $totalDiscount = collect($discounts)->sum('monto');
        $totalConDescuento = max(0, $total - $totalDiscount);

        DB::beginTransaction();
        try {
            // Crear cabecera de cotización
            $cotizacion = Cotizacion::create([
                'ci_cliente' => $ci,
                'fecha' => now()->toDateString(),
                'total' => $totalConDescuento,
                'observaciones' => $request->input('observaciones'),
            ]);

            // Crear detalles
            foreach ($items as $item) {
                if ($item->producto) {
                    CotizacionDetalle::create([
                        'cotizacion_id' => $cotizacion->id,
                        'idproducto' => $item->idproducto,
                        'cantidad' => $item->cantidad,
                        'precio_unitario' => $item->producto->precio,
                    ]);
                }
            }

            // Registrar en bitácora
            Bitacora::registrar(
                'INSERTAR',
                'cotizaciones',
                $cotizacion->id,
                "Cotización #{$cotizacion->id} guardada con total de " . number_format($totalConDescuento, 2) . " BOB. Cliente CI: {$ci}"
            );

            DB::commit();

            return redirect()->route('cotizaciones.guardadas')->with('success', '¡Cotización guardada exitosamente!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('carrito.index')->with('error', 'Error al guardar la cotización: ' . $e->getMessage());
        }
    }

    /**
     * Listar todas las cotizaciones guardadas del cliente autenticado.
     */
    public function verCotizacionesGuardadas()
    {
        $ci = Auth::user()->ci;
        $cotizaciones = Cotizacion::with('detalles.producto')
            ->where('ci_cliente', $ci)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('cotizacion.guardadas', compact('cotizaciones'));
    }

    /**
     * Cargar una cotización guardada en el carrito activo.
     */
    public function cargarCotizacion($id)
    {
        $ci = Auth::user()->ci;
        $cotizacion = Cotizacion::with('detalles.producto')
            ->where('ci_cliente', $ci)
            ->findOrFail($id);

        DB::beginTransaction();
        try {
            // Vaciar el carrito actual
            Carrito::where('ci_usuario', $ci)->delete();

            // Insertar los productos de la cotización al carrito
            foreach ($cotizacion->detalles as $detalle) {
                if ($detalle->producto && $detalle->producto->cantidad > 0) {
                    $cantidadDisponible = min($detalle->cantidad, $detalle->producto->cantidad);
                    Carrito::create([
                        'ci_usuario' => $ci,
                        'idproducto' => $detalle->idproducto,
                        'cantidad' => $cantidadDisponible,
                    ]);
                }
            }

            // Registrar en bitácora
            Bitacora::registrar(
                'CONSULTAR',
                'cotizaciones',
                $cotizacion->id,
                "Cotización #{$cotizacion->id} cargada en el carrito. Cliente CI: {$ci}"
            );

            DB::commit();

            return redirect()->route('carrito.index')->with('success', '¡Cotización cargada en tu carrito! Revisa los productos.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('cotizaciones.guardadas')->with('error', 'Error al cargar la cotización: ' . $e->getMessage());
        }
    }

    /**
     * Eliminar una cotización guardada.
     */
    public function eliminarCotizacion($id)
    {
        $ci = Auth::user()->ci;
        $cotizacion = Cotizacion::where('ci_cliente', $ci)->findOrFail($id);

        $cotizacion->delete();

        Bitacora::registrar(
            'ELIMINAR',
            'cotizaciones',
            $id,
            "Cotización #{$id} eliminada. Cliente CI: {$ci}"
        );

        return redirect()->route('cotizaciones.guardadas')->with('success', 'Cotización eliminada correctamente.');
    }

    /**
     * Agregar todos los productos de una promoción/combo al carrito y redirigir.
     */
    public function addPromocion($id)
    {
        $promocion = Promocion::with('productos')->findOrFail($id);

        // Verificar que la promoción siga vigente
        if (!$promocion->estaVigente()) {
            return redirect()->route('inventario')->with('error', 'Esta promoción ya no está vigente.');
        }

        // Verificar stock de todos los productos
        foreach ($promocion->productos as $producto) {
            if ($producto->cantidad < 1) {
                return redirect()->route('inventario')->with('error', "El producto '{$producto->nombre}' no tiene stock disponible.");
            }
        }

        // Agregar cada producto al carrito
        foreach ($promocion->productos as $producto) {
            if (Auth::check()) {
                $ci = Auth::user()->ci;
                $dbItem = Carrito::where('ci_usuario', $ci)->where('idproducto', $producto->idproducto)->first();

                if ($dbItem) {
                    if ($dbItem->cantidad + 1 <= $producto->cantidad) {
                        $dbItem->cantidad += 1;
                        $dbItem->save();
                    }
                } else {
                    Carrito::create([
                        'ci_usuario' => $ci,
                        'idproducto' => $producto->idproducto,
                        'cantidad' => 1
                    ]);
                }
            } else {
                $cart = session()->get('carrito', []);
                $pid = $producto->idproducto;
                if (isset($cart[$pid])) {
                    if ($cart[$pid]['cantidad'] + 1 <= $producto->cantidad) {
                        $cart[$pid]['cantidad'] += 1;
                    }
                } else {
                    $cart[$pid] = ['cantidad' => 1];
                }
                session()->put('carrito', $cart);
            }
        }

        return redirect()->route('carrito.index')->with('success', "¡Promoción '{$promocion->nombre}' agregada al carrito!");
    }

    /**
     * Calcular descuentos dinámicos basados en promociones activas.
     * Retorna un array de descuentos aplicables.
     */
    private function getCartDiscounts(array $cartItems): array
    {
        $discounts = [];

        // IDs de productos en el carrito
        $cartProductIds = collect($cartItems)->pluck('idproducto')->toArray();

        if (empty($cartProductIds)) {
            return $discounts;
        }

        // Obtener promociones activas vigentes
        $promociones = Promocion::with('productos')
            ->where('estado', 'Activo')
            ->where('fecha_inicio', '<=', now())
            ->where('fecha_fin', '>=', now())
            ->get();

        foreach ($promociones as $promo) {
            $promoProductIds = $promo->productos->pluck('idproducto')->toArray();

            if ($promo->tipo === 'Combo') {
                // Verificar si TODOS los productos del combo están en el carrito
                $allPresent = empty(array_diff($promoProductIds, $cartProductIds));
                if ($allPresent && $promo->precio_combo !== null) {
                    // Calcular el precio original sumando los precios individuales
                    $precioOriginal = 0;
                    foreach ($cartItems as $item) {
                        if (in_array($item['idproducto'], $promoProductIds)) {
                            $precioOriginal += $item['precio']; // precio unitario × 1
                        }
                    }
                    $ahorro = $precioOriginal - $promo->precio_combo;
                    if ($ahorro > 0) {
                        $discounts[] = [
                            'nombre' => "Combo: {$promo->nombre}",
                            'descripcion' => "Precio combo: " . number_format($promo->precio_combo, 2) . " Bs.",
                            'monto' => round($ahorro, 2),
                        ];
                    }
                }
            } elseif ($promo->tipo === 'Global' && $promo->descuento_porcentaje > 0) {
                // Aplicar descuento porcentual a cada producto del carrito que esté en la promo
                $descuentoTotal = 0;
                foreach ($cartItems as $item) {
                    if (in_array($item['idproducto'], $promoProductIds)) {
                        $descuentoTotal += ($item['subtotal'] * $promo->descuento_porcentaje / 100);
                    }
                }
                if ($descuentoTotal > 0) {
                    $discounts[] = [
                        'nombre' => "{$promo->nombre} (-{$promo->descuento_porcentaje}%)",
                        'descripcion' => "Descuento aplicado a productos seleccionados",
                        'monto' => round($descuentoTotal, 2),
                    ];
                }
            }
        }

        return $discounts;
    }
}
