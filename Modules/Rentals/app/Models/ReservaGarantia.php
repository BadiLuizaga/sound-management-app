<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;

/**
 * Garantía de una reserva, SEPARADA del alquiler (C2): un depósito en dinero
 * es un pago con concepto 'garantia' y nunca modifica `reservas.estado_pago`.
 * Fase 2 — Etapa 4 (paso 1): solo estructura y relaciones.
 */
class ReservaGarantia extends Model
{
    public const TIPO_DINERO = 'dinero';
    public const TIPO_DOCUMENTO = 'documento';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_RECIBIDA = 'recibida';
    public const ESTADO_DEVUELTA = 'devuelta';
    public const ESTADO_DEVUELTA_PARCIAL = 'devuelta_parcial';
    public const ESTADO_RETENIDA = 'retenida';

    protected $table = 'reserva_garantias';

    protected $fillable = [
        'reserva_id', 'tipo', 'monto', 'moneda', 'descripcion', 'estado', 'pago_id', 'reembolso_id',
        'monto_retenido', 'incidencia_id', 'ci_registrado_por', 'ci_resuelto_por',
        'recibida_at', 'resuelta_at', 'observaciones', 'clave_idempotencia',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'monto_retenido' => 'decimal:2',
            'recibida_at' => 'datetime',
            'resuelta_at' => 'datetime',
        ];
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function pago()
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function reembolso()
    {
        return $this->belongsTo(Reembolso::class, 'reembolso_id');
    }

    public function incidencia()
    {
        return $this->belongsTo(ReservaIncidencia::class, 'incidencia_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }

    public function resueltoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_resuelto_por', 'ci');
    }
}
