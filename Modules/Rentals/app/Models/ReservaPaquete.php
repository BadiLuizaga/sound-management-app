<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paquete incluido en una reserva, con el precio congelado al reservar.
 */
class ReservaPaquete extends Model
{
    protected $table = 'reserva_paquetes';

    protected $fillable = [
        'reserva_id',
        'paquete_id',
        'cantidad',
        'precio_hora',
        'horas',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'horas' => 'integer',
            'precio_hora' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function paquete()
    {
        return $this->belongsTo(Paquete::class, 'paquete_id')->withTrashed();
    }
}
