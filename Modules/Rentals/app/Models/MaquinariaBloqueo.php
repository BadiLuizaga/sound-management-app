<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;

/**
 * Unidades de un equipo fuera de servicio durante un intervalo (mantenimiento,
 * daño, faltante u otro). `hasta` nulo = hasta que se levante el bloqueo.
 * La disponibilidad los descuenta sin margen de preparación.
 * Fase 2 — Etapa 4 (paso 1): solo estructura y relaciones.
 */
class MaquinariaBloqueo extends Model
{
    public const MOTIVO_MANTENIMIENTO = 'mantenimiento';
    public const MOTIVO_DANO = 'dano';
    public const MOTIVO_FALTANTE = 'faltante';
    public const MOTIVO_OTRO = 'otro';

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_LEVANTADO = 'levantado';

    protected $table = 'maquinaria_bloqueos';

    protected $fillable = [
        'maquinaria_id', 'cantidad', 'desde', 'hasta', 'motivo', 'descripcion', 'mantenimiento_id',
        'incidencia_id', 'estado', 'ci_registrado_por', 'ci_levantado_por', 'levantado_at',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'desde' => 'datetime',
            'hasta' => 'datetime',
            'levantado_at' => 'datetime',
        ];
    }

    public function maquinaria()
    {
        return $this->belongsTo(Maquinaria::class, 'maquinaria_id');
    }

    public function mantenimiento()
    {
        return $this->belongsTo(Mantenimiento::class, 'mantenimiento_id');
    }

    public function incidencia()
    {
        return $this->belongsTo(ReservaIncidencia::class, 'incidencia_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }

    public function levantadoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_levantado_por', 'ci');
    }
}
