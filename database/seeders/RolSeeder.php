<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Access\Models\Rol;

/**
 * Roles de personal del sistema. Idempotente: se identifican por `nombre`.
 *
 * El perfil "Cliente" no es un rol de esta tabla: un cliente es un usuario
 * con tipoPersona = 'C' que se registra desde el portal web. `rol` y
 * `estadoRol` son solo para personal (tipoPersona = 'E').
 */
class RolSeeder extends Seeder
{
    public const ROLES = [
        'Administrador' => 'Control total del sistema.',
        'Encargado' => 'Supervisión de la operación: ventas, alquileres, inventario y mantenimiento.',
        'Técnico' => 'Equipos y mantenimiento; reporta daños e incidencias.',
        'Almacenero' => 'Gestión de inventario y existencias.',
        'Vendedor' => 'Atención al cliente, ventas y alquileres.',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $nombre => $descripcion) {
            $rol = Rol::where('nombre', $nombre)->first();

            if ($rol) {
                continue; // no se pisan descripciones editadas por el administrador
            }

            // `rol.id` no es autoincremental (esquema heredado)
            $siguienteId = (int) (Rol::max('id') ?? 0) + 1;

            Rol::create([
                'id' => $siguienteId,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
            ]);
        }
    }
}
