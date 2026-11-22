<?php

namespace Modules\Sales\Models;

use Modules\Access\Models\Usuario;
use Modules\Finance\Models\CajaEvento;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Support\Dinero;

use Illuminate\Database\Eloquent\Model;

/**
 * Caja de un usuario (titular = user_id, que guarda el CI).
 *
 * version_arqueo: 1 = arqueo heredado (cajas cerradas antes de la Etapa 3,
 * no se recalculan); 2 = arqueo por caja_id y método de pago.
 * Las cajas no se eliminan ni se reabren.
 */
class Caja extends Model
{
    public const ABIERTA = 'abierta';
    public const CERRADA = 'cerrada';

    public const ARQUEO_HEREDADO = 1;
    public const ARQUEO_POR_METODO = 2;

    protected $fillable = [
        'user_id',
        'monto_apertura',
        'monto_cierre',
        'diferencia',
        'estado',
        'fecha_apertura',
        'fecha_cierre',
        'version_arqueo',
        'efectivo_esperado',
        'efectivo_contado',
        'resumen',
        'ci_cerrado_por',
        'cierre_supervisado',
        'observaciones',
        'clave_cierre',
    ];

    protected function casts(): array
    {
        return [
            'monto_apertura' => 'decimal:2',
            'monto_cierre' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'efectivo_contado' => 'decimal:2',
            'resumen' => 'array',
            'cierre_supervisado' => 'boolean',
            'version_arqueo' => 'integer',
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('Las cajas no se eliminan: forman parte del historial financiero.');
        });
    }

    public function estaAbierta(): bool
    {
        return $this->estado === self::ABIERTA;
    }

    public function esHeredada(): bool
    {
        return (int) $this->version_arqueo === self::ARQUEO_HEREDADO && $this->estado === self::CERRADA;
    }

    public function fondo(): Dinero
    {
        return Dinero::deDecimal((string) $this->monto_apertura, 'BOB');
    }

    /** Titular de la caja. */
    public function user()
    {
        // La tabla cajas guarda el CI en user_id, por lo que relacionamos con Usuario
        return $this->belongsTo(Usuario::class, 'user_id', 'ci');
    }

    public function cerradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_cerrado_por', 'ci');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'caja_id');
    }

    /** Reembolsos cuyo dinero se entregó desde esta caja. */
    public function reembolsos()
    {
        return $this->hasMany(Reembolso::class, 'caja_id');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoCaja::class, 'caja_id');
    }

    /** Movimientos (en otras cajas) que corrigen el arqueo de esta caja cerrada. */
    public function correcciones()
    {
        return $this->hasMany(MovimientoCaja::class, 'caja_corregida_id');
    }

    public function eventos()
    {
        return $this->hasMany(CajaEvento::class, 'caja_id')->orderBy('id');
    }
}
