<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de webhook recibido (idempotencia por proveedor + evento_id).
 */
class WebhookEvento extends Model
{
    protected $table = 'webhook_eventos';

    protected $fillable = [
        'proveedor', 'evento_id', 'tipo', 'firma_valida', 'payload', 'cabeceras',
        'pago_id', 'procesado_at', 'intentos', 'resultado', 'error',
    ];

    protected function casts(): array
    {
        return [
            'firma_valida' => 'boolean',
            'payload' => 'array',
            'cabeceras' => 'array',
            'procesado_at' => 'datetime',
        ];
    }

    public function pago()
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }
}
