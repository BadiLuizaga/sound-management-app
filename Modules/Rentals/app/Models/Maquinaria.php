<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

class Maquinaria extends Model
{
    protected $table = 'maquinarias';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'precio_hora',
        'precio_dia',
        'estado',
        'cantidad_total',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_total' => 'integer',
        ];
    }

    /**
     * Relación con los detalles del alquiler
     */
    public function detalles()
    {
        return $this->hasMany(AlquilerDetalle::class, 'maquinaria_id');
    }

    /**
     * Paquetes en los que se incluye este equipo
     */
    public function paqueteItems()
    {
        return $this->hasMany(PaqueteItem::class, 'maquinaria_id');
    }

    /**
     * Unidades asignadas a reservas confirmadas
     */
    public function reservaEquipos()
    {
        return $this->hasMany(ReservaEquipo::class, 'maquinaria_id');
    }

    /**
     * Unidades fuera de servicio (mantenimiento, daño, faltante) — Etapa 4
     */
    public function bloqueos()
    {
        return $this->hasMany(MaquinariaBloqueo::class, 'maquinaria_id');
    }
}
