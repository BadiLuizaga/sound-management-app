<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Línea del checklist: unidades de un equipo y su estado al entregar o devolver.
 */
class ReservaEntregaDetalle extends Model
{
    public const ESTADO_BUENO = 'bueno';
    public const ESTADO_DANADO = 'danado';
    public const ESTADO_FALTANTE = 'faltante';

    protected $table = 'reserva_entrega_detalles';

    protected $fillable = ['reserva_entrega_id', 'maquinaria_id', 'cantidad', 'estado_equipo', 'observaciones'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
        ];
    }

    public function entrega()
    {
        return $this->belongsTo(ReservaEntrega::class, 'reserva_entrega_id');
    }

    public function maquinaria()
    {
        return $this->belongsTo(Maquinaria::class, 'maquinaria_id');
    }
}
