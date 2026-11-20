<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Composición de un paquete: qué equipo y cuántas unidades incluye.
 */
class PaqueteItem extends Model
{
    protected $table = 'paquete_items';

    protected $fillable = [
        'paquete_id',
        'maquinaria_id',
        'cantidad',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
        ];
    }

    public function paquete()
    {
        return $this->belongsTo(Paquete::class, 'paquete_id');
    }

    public function maquinaria()
    {
        return $this->belongsTo(Maquinaria::class, 'maquinaria_id');
    }
}
