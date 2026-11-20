<?php

namespace Modules\Finance\Aplicadores;

use Modules\Finance\Models\Pago;

/**
 * Aplica un pago ya recibido a su destino (venta o reserva).
 * Se ejecuta dentro de una transacción abierta por PagoService con el pago bloqueado.
 */
interface AplicadorPago
{
    /**
     * @return array<string, mixed> columnas del pago a actualizar (p. ej. nro_factura)
     *
     * @throws \Modules\Finance\Exceptions\AplicacionFallida por reglas de negocio
     */
    public function aplicar(Pago $pago): array;
}
