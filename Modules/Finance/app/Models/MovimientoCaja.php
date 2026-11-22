<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;
use Modules\Finance\Support\Dinero;
use Modules\Sales\Models\Caja;

/**
 * Ingreso o egreso manual de efectivo. SOLO INSERCIÓN: una corrección es un
 * movimiento inverso que referencia al original (revierte_id); el original no
 * se modifica ni se elimina.
 */
class MovimientoCaja extends Model
{
    public const UPDATED_AT = null;

    public const INGRESO = 'ingreso';
    public const EGRESO = 'egreso';

    protected $table = 'movimientos_caja';

    protected $fillable = [
        'uuid', 'clave_idempotencia', 'caja_id', 'tipo', 'monto', 'moneda', 'motivo',
        'revierte_id', 'caja_corregida_id', 'ci_registrado_por', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Los movimientos de caja son de solo inserción: registra un movimiento correctivo.');
        });
        static::deleting(function () {
            throw new \LogicException('Los movimientos de caja son de solo inserción: registra un movimiento correctivo.');
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

    public function caja()
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function cajaCorregida()
    {
        return $this->belongsTo(Caja::class, 'caja_corregida_id');
    }

    /** El movimiento original que este revierte. */
    public function revierte()
    {
        return $this->belongsTo(self::class, 'revierte_id');
    }

    /** El movimiento que revierte a este (como mucho uno). */
    public function reversion()
    {
        return $this->hasOne(self::class, 'revierte_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }
}
