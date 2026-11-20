<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sales\Models\MetodoPago;

/**
 * Métodos de pago iniciales (configuración técnica, no datos comerciales).
 *
 * Idempotente: se identifican por `codigo`. Si un método ya existe, solo se
 * actualiza su nombre; nunca se cambia su ID ni se reactiva si un
 * administrador lo desactivó. El código de la aplicación consulta siempre el
 * método por código (MetodoPago::idPorCodigo), nunca por un ID fijo.
 */
class MetodoPagoSeeder extends Seeder
{
    public const METODOS = [
        MetodoPago::EFECTIVO => 'Efectivo',
        MetodoPago::TARJETA => 'Tarjeta',
        MetodoPago::TRANSFERENCIA => 'Transferencia bancaria',
        MetodoPago::QR => 'QR',
        MetodoPago::PAYPAL => 'PayPal',
    ];

    public function run(): void
    {
        foreach (self::METODOS as $codigo => $nombre) {
            $metodo = MetodoPago::where('codigo', $codigo)->first();

            if ($metodo) {
                $metodo->update(['nombre' => $nombre]);
                continue;
            }

            // Fila existente sin código con el mismo nombre (p. ej. tras revertir y
            // rehacer la migración que añadió `codigo`): se le asigna el código en
            // lugar de crear un duplicado.
            $sinCodigo = MetodoPago::whereNull('codigo')->where('nombre', $nombre)->first();
            if ($sinCodigo) {
                $sinCodigo->update(['codigo' => $codigo]);
                continue;
            }

            // `metodoPago.id` no es autoincremental (esquema heredado)
            $siguienteId = (int) (MetodoPago::max('id') ?? 0) + 1;

            MetodoPago::create([
                'id' => $siguienteId,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'activo' => true,
            ]);
        }
    }
}
