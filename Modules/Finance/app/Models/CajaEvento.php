<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;
use Modules\Sales\Models\Caja;

/**
 * Historial de una caja: SOLO INSERCIÓN. Guarda el titular de la caja y el
 * responsable de la acción (distintos en un cierre supervisado).
 */
class CajaEvento extends Model
{
    public const UPDATED_AT = null;

    public const APERTURA = 'apertura';
    public const CIERRE = 'cierre';
    public const CIERRE_SUPERVISADO = 'cierre_supervisado';
    public const INGRESO = 'ingreso';
    public const EGRESO = 'egreso';
    public const REVERSION = 'reversion_movimiento';
    public const CORRECCION_RECIBIDA = 'correccion_recibida';
    public const REEMBOLSO_ENTREGADO = 'reembolso_entregado';
    public const INTENTO_RECHAZADO = 'intento_rechazado';

    protected $table = 'caja_eventos';

    protected $fillable = ['caja_id', 'tipo', 'ci_titular', 'ci_responsable', 'datos', 'ip'];

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
            throw new \LogicException('El historial de caja es de solo inserción.');
        });
        static::deleting(function () {
            throw new \LogicException('El historial de caja es de solo inserción.');
        });
    }

    public function caja()
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function titular()
    {
        return $this->belongsTo(Usuario::class, 'ci_titular', 'ci');
    }

    public function responsable()
    {
        return $this->belongsTo(Usuario::class, 'ci_responsable', 'ci');
    }
}
