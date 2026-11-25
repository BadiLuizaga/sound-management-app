<?php

namespace Modules\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Usuario;

/**
 * Reserva de paquetes por horas.
 *
 * `estado` (ciclo de la reserva) y `estado_pago` son independientes: la
 * confirmación no depende del pago. Los equipos solo se asignan
 * (reserva_equipos) cuando la reserva queda confirmada.
 *
 * Fase 2 — Etapa 1: solo estructura y relaciones.
 * Fase 2 — Etapa 4 (paso 1): canal, cargos, hitos del ciclo e idempotencia;
 * relaciones con eventos, entregas, incidencias y cargos. Sin lógica.
 * SOUND POS no gestiona garantías ni horas adicionales.
 */
class Reserva extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_CONFIRMADA = 'confirmada';
    public const ESTADO_EN_CURSO = 'en_curso';
    public const ESTADO_FINALIZADA = 'finalizada';
    public const ESTADO_CANCELADA = 'cancelada';
    public const ESTADO_EXPIRADA = 'expirada';
    public const ESTADO_RECHAZADA = 'rechazada';

    public const PAGO_PENDIENTE = 'pendiente';
    public const PAGO_PARCIAL = 'parcial';
    public const PAGO_PAGADO = 'pagado';
    public const PAGO_REEMBOLSADO = 'reembolsado';

    public const CANAL_ERP = 'erp';
    public const CANAL_POS = 'pos';
    public const CANAL_PORTAL = 'portal';

    protected $table = 'reservas';

    protected $fillable = [
        'codigo',
        'ci_cliente',
        'estado',
        'estado_pago',
        'fecha_inicio',
        'fecha_fin',
        'horas',
        'total',
        'moneda',
        'expira_at',
        'confirmada_at',
        'cancelada_at',
        'motivo_cancelacion',
        'observaciones',
        'ci_registrado_por',
        // Etapa 4
        'canal',
        'total_cargos',
        'entregada_at',
        'devuelta_at',
        'liquidada_at',
        'lugar_entrega',
        'clave_idempotencia',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
            'expira_at' => 'datetime',
            'confirmada_at' => 'datetime',
            'cancelada_at' => 'datetime',
            'horas' => 'integer',
            'total' => 'decimal:2',
            'total_cargos' => 'decimal:2',
            'entregada_at' => 'datetime',
            'devuelta_at' => 'datetime',
            'liquidada_at' => 'datetime',
        ];
    }

    public function cliente()
    {
        return $this->belongsTo(Usuario::class, 'ci_cliente', 'ci');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'ci_registrado_por', 'ci');
    }

    public function paquetes()
    {
        return $this->hasMany(ReservaPaquete::class, 'reserva_id');
    }

    public function equipos()
    {
        return $this->hasMany(ReservaEquipo::class, 'reserva_id');
    }

    public function eventos()
    {
        return $this->hasMany(ReservaEvento::class, 'reserva_id');
    }

    public function entregas()
    {
        return $this->hasMany(ReservaEntrega::class, 'reserva_id');
    }

    public function incidencias()
    {
        return $this->hasMany(ReservaIncidencia::class, 'reserva_id');
    }

    public function cargos()
    {
        return $this->hasMany(ReservaCargo::class, 'reserva_id');
    }
}
