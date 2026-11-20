<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Sales\Models\FacturaVenta;
use Modules\Access\Models\Usuario;

class Devolucion extends Model
{
    protected $table = 'devoluciones';

    protected $fillable = [
        'nro_factura',
        'tipo',
        'motivo',
        'fecha',
        'estado',
        'ci_empleado',
        'observaciones',
        // Fase 2 — Etapa 2: importes de la devolución (la nota de venta no se modifica)
        'monto_total',
        'ci_aprobado_por',
        'aprobado_at',
    ];

    protected function casts(): array
    {
        return [
            'monto_total' => 'decimal:2',
            'aprobado_at' => 'datetime',
        ];
    }

    /**
     * Reembolsos vinculados a esta devolución (módulo Finance)
     */
    public function reembolsos()
    {
        return $this->hasMany(\Modules\Finance\Models\Reembolso::class, 'devolucion_id');
    }

    /**
     * Relación con la factura de venta
     */
    public function factura()
    {
        return $this->belongsTo(FacturaVenta::class, 'nro_factura', 'nro');
    }

    /**
     * Relación con el empleado que gestionó la devolución
     */
    public function empleado()
    {
        return $this->belongsTo(Usuario::class, 'ci_empleado', 'ci');
    }

    /**
     * Detalles de los productos devueltos
     */
    public function detalles()
    {
        return $this->hasMany(DevolucionDetalle::class, 'devolucion_id');
    }
}
