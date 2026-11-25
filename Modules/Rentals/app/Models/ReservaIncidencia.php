<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;

/**
 * Incidencia de una reserva: daño, faltante, retraso u otro.
 * Fase 2 — Etapa 4 (paso 1): solo estructura y relaciones.
 */
class ReservaIncidencia extends Model
{
    public const TIPO_DANO = 'dano';
    public const TIPO_FALTANTE = 'faltante';
    public const TIPO_RETRASO = 'retraso';
    public const TIPO_OTRO = 'otro';

    public const ESTADO_ABIERTA = 'abierta';
    public const ESTADO_RESUELTA = 'resuelta';
    public const ESTADO_ANULADA = 'anulada';

    protected $table = 'reserva_incidencias';

    protected $fillable = [
        'reserva_id', 'reserva_entrega_id', 'maquinaria_id', 'tipo', 'cantidad', 'descripcion',
        'costo_estimado', 'moneda', 'estado', 'resolucion', 'mantenimiento_id',
        'ci_registrado_por', 'ci_resuelto_por', 'resuelta_at',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'costo_estimado' => 'decimal:2',
            'resuelta_at' => 'datetime',
        ];
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function entrega()
    {
        return $this->belongsTo(ReservaEntrega::class, 'reserva_entrega_id');
    }

    public function maquinaria()
    {
        return $this->belongsTo(Maquinaria::class, 'maquinaria_id');
    }

    public function mantenimiento()
    {
        return $this->belongsTo(Mantenimiento::class, 'mantenimiento_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }

    public function resueltoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_resuelto_por', 'ci');
    }

    public function cargos()
    {
        return $this->hasMany(ReservaCargo::class, 'incidencia_id');
    }

    public function garantias()
    {
        return $this->hasMany(ReservaGarantia::class, 'incidencia_id');
    }

    public function bloqueos()
    {
        return $this->hasMany(MaquinariaBloqueo::class, 'incidencia_id');
    }
}
