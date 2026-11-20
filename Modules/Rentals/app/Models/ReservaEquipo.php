<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Unidades de un equipo bloqueadas por una reserva CONFIRMADA durante su
 * intervalo. Al cancelar o expirar la reserva pasan a 'liberado'.
 */
class ReservaEquipo extends Model
{
    public const ESTADO_RESERVADO = 'reservado';
    public const ESTADO_LIBERADO = 'liberado';

    protected $table = 'reserva_equipos';

    protected $fillable = [
        'reserva_id',
        'maquinaria_id',
        'cantidad',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'liberado_at',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
            'liberado_at' => 'datetime',
        ];
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function maquinaria()
    {
        return $this->belongsTo(Maquinaria::class, 'maquinaria_id');
    }
}
