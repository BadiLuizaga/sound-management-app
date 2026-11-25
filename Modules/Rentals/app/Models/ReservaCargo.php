<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;

/**
 * Cargo adicional de una reserva (horas extra, daño, faltante u otro).
 * No se elimina: se anula. Fase 2 — Etapa 4 (paso 1): solo estructura.
 */
class ReservaCargo extends Model
{
    public const CONCEPTO_HORAS_EXTRA = 'horas_extra';
    public const CONCEPTO_DANO = 'dano';
    public const CONCEPTO_FALTANTE = 'faltante';
    public const CONCEPTO_OTRO = 'otro';

    public const ESTADO_VIGENTE = 'vigente';
    public const ESTADO_ANULADO = 'anulado';

    protected $table = 'reserva_cargos';

    protected $fillable = [
        'reserva_id', 'concepto', 'descripcion', 'horas', 'monto', 'moneda', 'incidencia_id',
        'estado', 'motivo_anulacion', 'ci_registrado_por', 'ci_anulado_por', 'anulado_at', 'clave_idempotencia',
    ];

    protected function casts(): array
    {
        return [
            'horas' => 'integer',
            'monto' => 'decimal:2',
            'anulado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('Los cargos no se eliminan: se anulan.');
        });
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function incidencia()
    {
        return $this->belongsTo(ReservaIncidencia::class, 'incidencia_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }

    public function anuladoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_anulado_por', 'ci');
    }
}
