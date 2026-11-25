<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;

/**
 * Entrega o devolución de los equipos de una reserva (cabecera del checklist).
 * Fase 2 — Etapa 4 (paso 1): solo estructura y relaciones.
 */
class ReservaEntrega extends Model
{
    public const TIPO_ENTREGA = 'entrega';
    public const TIPO_DEVOLUCION = 'devolucion';

    protected $table = 'reserva_entregas';

    protected $fillable = ['reserva_id', 'tipo', 'fecha', 'ci_responsable', 'observaciones', 'clave_idempotencia'];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function detalles()
    {
        return $this->hasMany(ReservaEntregaDetalle::class, 'reserva_entrega_id');
    }

    public function responsable()
    {
        return $this->belongsTo(Usuario::class, 'ci_responsable', 'ci');
    }

    public function incidencias()
    {
        return $this->hasMany(ReservaIncidencia::class, 'reserva_entrega_id');
    }
}
