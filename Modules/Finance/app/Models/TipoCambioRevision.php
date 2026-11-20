<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historial de modificaciones de una tasa (solo inserción).
 */
class TipoCambioRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'tipo_cambio_revisiones';

    protected $fillable = ['tipo_cambio_id', 'tasa_anterior', 'tasa_nueva', 'motivo', 'ci_usuario'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El historial de tasas es de solo inserción.'));
        static::deleting(fn () => throw new \LogicException('El historial de tasas es de solo inserción.'));
    }

    public function tipoCambio()
    {
        return $this->belongsTo(TipoCambio::class, 'tipo_cambio_id');
    }
}
