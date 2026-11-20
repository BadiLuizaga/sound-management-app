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
use Modules\Sales\Models\MetodoPago;

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

        // Pago en línea (módulo Finance): solo se ofrece si PayPal está configurado
        // (client_id + secret + webhook_id), el método PAYPAL está activo y hay
        // tipo de cambio del día. El importe en USD es informativo: el servidor
        // lo recalcula al crear la orden.
        $disponibilidad = app(\Modules\Finance\Services\PagoService::class)->disponibilidadEnLinea();
        $pagoEnLineaDisponible = $disponibilidad['disponible'];
        $pagoEnLinea = null;
        if ($pagoEnLineaDisponible && $totalConDescuento > 0) {
            $bob = \Modules\Finance\Support\Dinero::deDecimal((string) round($totalConDescuento, 2), 'BOB');
            $pagoEnLinea = [
                'monto_bob' => $bob->decimal(),
                'monto_proveedor' => app(\Modules\Finance\Services\TipoCambioService::class)->convertir($bob, $disponibilidad['tasa'])->decimal(),
                'moneda_proveedor' => $disponibilidad['tasa']->moneda_base,
                'tasa' => (string) $disponibilidad['tasa']->tasa,
                'fecha_tasa' => $disponibilidad['tasa']->fecha_aplicacion->format('d/m/Y'),
            ];
        }
        $motivoPagoNoDisponible = $disponibilidad['motivo'];

        return view('carrito.index', compact('cartItems', 'total', 'discounts', 'totalDiscount', 'totalConDescuento', 'pagoEnLineaDisponible', 'pagoEnLinea', 'motivoPagoNoDisponible'));
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
     * Checkout antiguo (Fase 1). OBSOLETO desde la Etapa 2: el cobro en línea se
     * hace con el flujo del módulo Finance (orden y captura en el servidor,
     * rutas /pagos/paypal/...). Esta ruta ya NO registra ventas ni pagos; solo
     * informa, para que ningún formulario antiguo pueda generar una venta.
     */
    public function checkout()
    {
        return redirect()->route('carrito.index')->with('error', 'El pago en línea se realiza con el botón de PayPal del carrito. No se registró ninguna venta.');
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
        // Lógica movida sin cambios a CarritoService (la reutiliza el pago en línea)
        return app(\Modules\Sales\Services\CarritoService::class)->descuentos($cartItems);
    }
}
