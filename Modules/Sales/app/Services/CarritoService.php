<?php

namespace Modules\Sales\Services;

use Modules\Finance\Support\Dinero;
use Modules\Sales\Models\Carrito;
use Modules\Sales\Models\Promocion;

/**
 * Cálculo del carrito en el servidor (precios actuales de la BD y promociones
 * vigentes). Lo usan el carrito y el pago en línea (Finance), para que el
 * importe que se cobra nunca venga del navegador.
 */
class CarritoService
{
    /**
     * Resumen del carrito persistido de un usuario.
     *
     * @return array{items: array<int, array>, descuentos: array<int, array>, total: Dinero, total_con_descuento: Dinero}
     */
    public function resumen(int|string $ciUsuario): array
    {
        $items = [];
        $subtotal = Dinero::cero('BOB');

        foreach (Carrito::with('producto')->where('ci_usuario', $ciUsuario)->orderBy('id')->get() as $linea) {
            if (!$linea->producto) {
                continue; // producto dado de baja: no se cobra
            }

            $precio = Dinero::deDecimal((string) $linea->producto->precio, 'BOB');
            $sub = Dinero::deCentavos($precio->centavos * (int) $linea->cantidad, 'BOB');

            $items[] = [
                'idproducto' => (int) $linea->idproducto,
                'nombre' => $linea->producto->nombre,
                'precio' => $precio->decimal(),
                'cantidad' => (int) $linea->cantidad,
                'subtotal' => $sub->decimal(),
            ];
            $subtotal = $subtotal->sumar($sub);
        }

        $descuentos = $this->descuentos(array_map(fn ($i) => [
            'idproducto' => $i['idproducto'],
            'precio' => (float) $i['precio'],
            'cantidad' => $i['cantidad'],
            'subtotal' => (float) $i['subtotal'],
        ], $items));

        $totalDescuento = Dinero::cero('BOB');
        foreach ($descuentos as $d) {
            $totalDescuento = $totalDescuento->sumar(Dinero::deDecimal((string) $d['monto'], 'BOB'));
        }

        $conDescuento = $subtotal->restar($totalDescuento);
        if ($conDescuento->centavos < 0) {
            $conDescuento = Dinero::cero('BOB');
        }

        return [
            'items' => $items,
            'descuentos' => $descuentos,
            'total' => $subtotal,
            'total_con_descuento' => $conDescuento,
        ];
    }

    /**
     * Descuentos de promociones activas (misma lógica que tenía CartController).
     *
     * @param array<int, array{idproducto:int, precio:float, cantidad:int, subtotal:float}> $cartItems
     */
    public function descuentos(array $cartItems): array
    {
        $discounts = [];
        $cartProductIds = collect($cartItems)->pluck('idproducto')->toArray();

        if (empty($cartProductIds)) {
            return $discounts;
        }

        $promociones = Promocion::with('productos')
            ->where('estado', 'Activo')
            ->where('fecha_inicio', '<=', now())
            ->where('fecha_fin', '>=', now())
            ->get();

        foreach ($promociones as $promo) {
            $promoProductIds = $promo->productos->pluck('idproducto')->toArray();

            if ($promo->tipo === 'Combo') {
                $allPresent = empty(array_diff($promoProductIds, $cartProductIds));
                if ($allPresent && $promo->precio_combo !== null) {
                    $precioOriginal = 0;
                    foreach ($cartItems as $item) {
                        if (in_array($item['idproducto'], $promoProductIds)) {
                            $precioOriginal += $item['precio'];
                        }
                    }
                    $ahorro = $precioOriginal - $promo->precio_combo;
                    if ($ahorro > 0) {
                        $discounts[] = [
                            'nombre' => "Combo: {$promo->nombre}",
                            'descripcion' => 'Precio combo: ' . number_format($promo->precio_combo, 2) . ' Bs.',
                            'monto' => round($ahorro, 2),
                        ];
                    }
                }
            } elseif ($promo->tipo === 'Global' && $promo->descuento_porcentaje > 0) {
                $descuentoTotal = 0;
                foreach ($cartItems as $item) {
                    if (in_array($item['idproducto'], $promoProductIds)) {
                        $descuentoTotal += ($item['subtotal'] * $promo->descuento_porcentaje / 100);
                    }
                }
                if ($descuentoTotal > 0) {
                    $discounts[] = [
                        'nombre' => "{$promo->nombre} (-{$promo->descuento_porcentaje}%)",
                        'descripcion' => 'Descuento aplicado a productos seleccionados',
                        'monto' => round($descuentoTotal, 2),
                    ];
                }
            }
        }

        return $discounts;
    }
}
