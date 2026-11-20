<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Support\Dinero;
use Modules\Procurement\Models\Devolucion;

/**
 * Reembolso total o parcial de un pago. Nunca se elimina.
 */
class Reembolso extends Model
{
    public const ORIGEN_APLICACION = 'aplicacion';
    public const ORIGEN_EXTERNO = 'externo_webhook';

    protected $table = 'reembolsos';

    protected $fillable = [
        'uuid', 'clave_idempotencia', 'pago_id', 'devolucion_id', 'estado', 'origen',
        'monto', 'moneda', 'monto_proveedor', 'moneda_proveedor', 'tasa_aplicada',
        'referencia_externa', 'motivo', 'ci_solicitado_por', 'ci_autorizado_por',
        'respuesta_proveedor', 'solicitado_at', 'autorizado_at', 'completado_at',
    ];

    protected $hidden = ['respuesta_proveedor'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoReembolso::class,
            'monto' => 'decimal:2',
            'monto_proveedor' => 'decimal:2',
            'tasa_aplicada' => 'decimal:6',
            'respuesta_proveedor' => 'array',
            'solicitado_at' => 'datetime',
            'autorizado_at' => 'datetime',
            'completado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('Los reembolsos no se eliminan: forman parte del historial financiero.');
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

    public function pago()
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function devolucion()
    {
        return $this->belongsTo(Devolucion::class, 'devolucion_id');
    }

    public function solicitadoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_solicitado_por', 'ci');
    }

    public function autorizadoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_autorizado_por', 'ci');
    }
}
