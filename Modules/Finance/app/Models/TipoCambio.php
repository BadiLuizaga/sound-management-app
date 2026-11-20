<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;

/**
 * Tasa BOB por 1 USD con vigencia diaria (fecha de aplicación explícita).
 */
class TipoCambio extends Model
{
    protected $table = 'tipos_cambio';

    protected $fillable = [
        'moneda_base', 'moneda_cotizada', 'tasa', 'fecha_aplicacion', 'fuente', 'ci_registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_aplicacion' => 'date',
            'tasa' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('Las tasas de cambio no se eliminan.');
        });
    }

    public function revisiones()
    {
        return $this->hasMany(TipoCambioRevision::class, 'tipo_cambio_id')->orderBy('id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'tipo_cambio_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }

    public function enUso(): bool
    {
        return $this->pagos()->exists();
    }
}
