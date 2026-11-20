<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MetodoPago extends Model
{
    // Códigos estables. El código de la aplicación nunca usa IDs fijos.
    public const EFECTIVO = 'EFECTIVO';
    public const TARJETA = 'TARJETA';
    public const TRANSFERENCIA = 'TRANSFERENCIA';
    public const QR = 'QR';
    public const PAYPAL = 'PAYPAL';

    protected $table = 'metodoPago';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['id', 'codigo', 'nombre', 'activo'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Solo métodos activos.
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Métodos activos que se pueden registrar en mostrador (caja / alquiler
     * presencial). PayPal queda excluido: es un pago en línea que solo se
     * registra tras verificarlo en el servidor.
     */
    public function scopePresenciales(Builder $query): Builder
    {
        return $query->where('activo', true)->where('codigo', '!=', self::PAYPAL);
    }

    /**
     * Regla de validación: ID de un método activo y presencial.
     */
    public static function reglaPresencial(): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('metodoPago', 'id')
            ->where(fn ($q) => $q->where('activo', true)->where('codigo', '!=', self::PAYPAL));
    }

    /**
     * Devuelve el ID del método activo con ese código.
     *
     * @throws \DomainException si el método no existe o está desactivado; así
     *                          nunca se registra un pago con un método inexistente.
     */
    public static function idPorCodigo(string $codigo): int
    {
        $id = static::query()->where('codigo', $codigo)->where('activo', true)->value('id');

        if ($id === null) {
            throw new \DomainException("El método de pago '{$codigo}' no está configurado o está desactivado.");
        }

        return (int) $id;
    }

    /**
     * Indica si existe y está activo el método con ese código.
     */
    public static function disponible(string $codigo): bool
    {
        return static::query()->where('codigo', $codigo)->where('activo', true)->exists();
    }

    /**
     * Relación con las facturas que usaron este método de pago
     */
    public function facturas()
    {
        return $this->hasMany(FacturaVenta::class, 'id_pago', 'id');
    }
}
