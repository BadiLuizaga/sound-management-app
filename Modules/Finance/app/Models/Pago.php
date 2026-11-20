<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Support\Dinero;
use Modules\Rentals\Models\Reserva;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\MetodoPago;

/**
 * Pago (libro único de dinero recibido). Nunca se elimina.
 * Los cambios de estado se hacen SOLO a través de PagoService, que valida
 * las transiciones y registra cada una en `pago_eventos`.
 */
class Pago extends Model
{
    public const CANAL_EN_LINEA = 'en_linea';
    public const CANAL_PRESENCIAL = 'presencial';

    public const PROVEEDOR_PAYPAL = 'paypal';
    public const PROVEEDOR_MANUAL = 'manual';

    protected $table = 'pagos';

    protected $fillable = [
        'uuid', 'clave_idempotencia', 'canal', 'proveedor', 'metodo_pago_id',
        'estado', 'estado_aplicacion',
        'monto', 'moneda', 'monto_recibido_efectivo', 'cambio_entregado',
        'monto_proveedor', 'moneda_proveedor', 'tipo_cambio_id', 'tasa_aplicada',
        'referencia_externa', 'referencia_captura',
        'ci_usuario', 'ci_registrado_por', 'ci_verificado_por', 'caja_id', 'nro_factura', 'reserva_id',
        'legacy_pago_externo_id', 'snapshot', 'respuesta_proveedor', 'motivo_revision', 'motivo_rechazo',
        'expira_at', 'recibido_at', 'verificado_at', 'aplicado_at',
    ];

    protected $hidden = ['respuesta_proveedor'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoPago::class,
            'estado_aplicacion' => EstadoAplicacion::class,
            'monto' => 'decimal:2',
            'monto_recibido_efectivo' => 'decimal:2',
            'cambio_entregado' => 'decimal:2',
            'monto_proveedor' => 'decimal:2',
            'tasa_aplicada' => 'decimal:6',
            'snapshot' => 'array',
            'respuesta_proveedor' => 'array',
            'expira_at' => 'datetime',
            'recibido_at' => 'datetime',
            'verificado_at' => 'datetime',
            'aplicado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('Los pagos no se eliminan: forman parte del historial financiero.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function importe(): Dinero
    {
        return Dinero::deDecimal((string) $this->monto, $this->moneda);
    }

    public function importeProveedor(): ?Dinero
    {
        return $this->monto_proveedor === null ? null
            : Dinero::deDecimal((string) $this->monto_proveedor, (string) $this->moneda_proveedor);
    }

    public function metodoPago()
    {
        return $this->belongsTo(MetodoPago::class, 'metodo_pago_id', 'id');
    }

    public function tipoCambio()
    {
        return $this->belongsTo(TipoCambio::class, 'tipo_cambio_id');
    }

    public function eventos()
    {
        return $this->hasMany(PagoEvento::class, 'pago_id')->orderBy('id');
    }

    public function reembolsos()
    {
        return $this->hasMany(Reembolso::class, 'pago_id');
    }

    public function pagador()
    {
        return $this->belongsTo(Usuario::class, 'ci_usuario', 'ci');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }

    public function verificadoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_verificado_por', 'ci');
    }

    public function caja()
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function venta()
    {
        return $this->belongsTo(FacturaVenta::class, 'nro_factura', 'nro');
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }
}
