<?php

namespace Modules\Finance\Aplicadores;

use Modules\Access\Models\Cliente;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Exceptions\AplicacionFallida;
use Modules\Finance\Models\Pago;
use Modules\Inventory\Models\Producto;
use Modules\Sales\Models\DetalleFacturaVenta;
use Modules\Sales\Models\FacturaVenta;

/**
 * Genera la venta del punto de venta (POS) a partir del snapshot registrado
 * por el cajero. Para efectivo y tarjeta se ejecuta al registrar el pago;
 * para transferencia y QR, solo después de verificar el ingreso.
 */
class AplicadorVentaPresencial implements AplicadorPago
{
    public function aplicar(Pago $pago): array
    {
        $snapshot = (array) $pago->snapshot;
        $items = (array) ($snapshot['items'] ?? []);
        $ciCliente = $snapshot['ci_cliente'] ?? null;

        if ($items === [] || $ciCliente === null) {
            throw new AplicacionFallida('El pago no tiene una venta asociada que aplicar.');
        }

        $ids = array_map(fn ($i) => (int) $i['idproducto'], $items);
        $productos = Producto::whereIn('idproducto', $ids)->lockForUpdate()->get()->keyBy('idproducto');

        foreach ($items as $item) {
            $producto = $productos->get((int) $item['idproducto']);
            if (!$producto) {
                throw new AplicacionFallida("El producto {$item['idproducto']} ya no está disponible.");
            }
            if ((int) $producto->cantidad < (int) $item['cantidad']) {
                throw new AplicacionFallida("Stock insuficiente para {$producto->nombre}. Stock disponible: {$producto->cantidad}.");
            }
        }

        // La nota exige un registro en `cliente`
        Cliente::firstOrCreate(['ci' => $ciCliente], ['categoria' => null]);

        $nro = (int) (FacturaVenta::lockForUpdate()->max('nro') ?? 0) + 1;

        FacturaVenta::create([
            'nro' => $nro,
            'fecha' => now(),
            'total' => $pago->monto,
            'ci_cliente' => $ciCliente,
            'ci_empleado' => $pago->ci_registrado_por,
            'id_pago' => $pago->metodo_pago_id,
        ]);

        foreach ($items as $item) {
            $producto = $productos->get((int) $item['idproducto']);
            $producto->cantidad -= (int) $item['cantidad'];
            $producto->save();

            DetalleFacturaVenta::create([
                'nro_factura' => $nro,
                'id_producto' => (int) $item['idproducto'],
                'precio_unitario' => $item['precio_unitario'],
                'cantidad' => (int) $item['cantidad'],
                'descuento' => $item['descuento'] ?? 0,
            ]);
        }

        Bitacora::registrar('INSERTAR', 'notaventa', $nro, "Venta en tienda #{$nro} por {$pago->monto} BOB (pago {$pago->uuid}). Cliente CI: {$ciCliente}");

        return ['nro_factura' => $nro];
    }
}
