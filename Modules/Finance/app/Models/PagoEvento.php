<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historial de un pago: SOLO INSERCIÓN. No se actualiza ni se elimina.
 */
class PagoEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pago_eventos';

    protected $fillable = [
        'pago_id', 'tipo',
        'estado_anterior', 'estado_nuevo',
        'estado_aplicacion_anterior', 'estado_aplicacion_nuevo',
        'datos', 'ci_usuario', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('El historial de pagos es de solo inserción.');
        });
        static::deleting(function () {
            throw new \LogicException('El historial de pagos es de solo inserción.');
        });
    }

    public function pago()
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
