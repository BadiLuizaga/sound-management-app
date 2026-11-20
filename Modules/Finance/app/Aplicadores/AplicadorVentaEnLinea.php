<?php

namespace Modules\Finance\Aplicadores;

use Modules\Access\Models\Cliente;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Exceptions\AplicacionFallida;
use Modules\Finance\Models\Pago;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\Carrito;
use Modules\Sales\Models\DetalleFacturaVenta;
use Modules\Sales\Models\FacturaVenta;

/**
 * Genera la venta de un pago en línea a partir del snapshot congelado al
 * crear el intento (precios cobrados). El stock se descuenta aquí, con el
 * pago ya recibido; nunca mientras el cliente estaba pagando.
 */
class AplicadorVentaEnLinea implements AplicadorPago
{
    public function aplicar(Pago $pago): array
    {
        $snapshot = (array) $pago->snapshot;
        $items = (array) ($snapshot['items'] ?? []);

        if ($items === [] || $pago->ci_usuario === null) {
            throw new AplicacionFallida('El pago no tiene un carrito asociado que aplicar.');
        }

        $ids = array_map(fn ($i) => (int) $i['idproducto'], $items);
        $productos = Producto::whereIn('idproducto', $ids)->lockForUpdate()->get()->keyBy('idproducto');

        foreach ($items as $item) {
            $producto = $productos->get((int) $item['idproducto']);
            if (!$producto) {
                throw new AplicacionFallida("El producto {$item['idproducto']} ya no está disponible.");
            }
            if ((int) $producto->cantidad < (int) $item['cantidad']) {
                throw new AplicacionFallida("Stock insuficiente para {$producto->nombre} (disponible {$producto->cantidad}, pagado {$item['cantidad']}).");
            }
        }

        Cliente::firstOrCreate(['ci' => $pago->ci_usuario], ['categoria' => 'Regular']);

        $nro = (int) (FacturaVenta::lockForUpdate()->max('nro') ?? 0) + 1;

        FacturaVenta::create([
            'nro' => $nro,
            'fecha' => now(),
            'total' => $pago->monto,
            'ci_cliente' => $pago->ci_usuario,
            'ci_empleado' => null,
            'id_pago' => $pago->metodo_pago_id,
        ]);

        foreach ($items as $item) {
            $producto = $productos->get((int) $item['idproducto']);
            $producto->cantidad -= (int) $item['cantidad'];
            $producto->save();

            DetalleFacturaVenta::create([
                'nro_factura' => $nro,
                'id_producto' => (int) $item['idproducto'],
                'precio_unitario' => $item['precio'],
                'cantidad' => (int) $item['cantidad'],
                'descuento' => 0,
            ]);
        }

        // Solo se retiran del carrito los productos cobrados
        Carrito::where('ci_usuario', $pago->ci_usuario)->whereIn('idproducto', $ids)->delete();

        Bitacora::registrar('INSERTAR', 'notaventa', $nro, "Venta en línea #{$nro} por {$pago->monto} BOB (pago {$pago->uuid}).");

        return ['nro_factura' => $nro];
    }
}
