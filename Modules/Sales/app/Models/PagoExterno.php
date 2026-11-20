<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pago confirmado por una pasarela externa. La referencia externa es única:
 * una misma orden pagada no puede generar dos ventas (idempotencia).
 */
class PagoExterno extends Model
{
    protected $table = 'pagos_externos';

    protected $fillable = [
        'proveedor',
        'referencia_externa',
        'referencia_captura',
        'monto',
        'moneda',
        'estado',
        'ci_usuario',
        'nro_factura',
    ];
}
