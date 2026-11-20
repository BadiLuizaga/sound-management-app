<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Paquete de alquiler (única forma de alquilar). Precio por hora, en BOB.
 * Fase 2 — Etapa 1: solo estructura y relaciones; la lógica de reservas se
 * implementa en su propia etapa.
 */
class Paquete extends Model
{
    use SoftDeletes;

    protected $table = 'paquetes';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'precio_hora',
        'moneda',
        'horas_minimas',
        'horas_maximas',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_hora' => 'decimal:2',
            'horas_minimas' => 'integer',
            'horas_maximas' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function items()
    {
        return $this->hasMany(PaqueteItem::class, 'paquete_id');
    }

    public function maquinarias()
    {
        return $this->belongsToMany(Maquinaria::class, 'paquete_items', 'paquete_id', 'maquinaria_id')
            ->withPivot('cantidad')
            ->withTimestamps();
    }
}
