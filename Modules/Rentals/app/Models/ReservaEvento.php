<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;

/**
 * Historial de una reserva: SOLO INSERCIÓN (mismo patrón que CajaEvento).
 * Fase 2 — Etapa 4 (paso 1): estructura; los servicios lo escriben en el paso 3.
 */
class ReservaEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'reserva_eventos';

    protected $fillable = ['reserva_id', 'tipo', 'estado_anterior', 'estado_nuevo', 'ci_responsable', 'datos', 'ip'];

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
            throw new \LogicException('El historial de la reserva es de solo inserción.');
        });
        static::deleting(function () {
            throw new \LogicException('El historial de la reserva es de solo inserción.');
        });
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function responsable()
    {
        return $this->belongsTo(Usuario::class, 'ci_responsable', 'ci');
    }
}
