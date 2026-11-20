<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos iniciales MÍNIMOS de una instalación limpia de Sound Management.
 *
 * Solo configuración técnica indispensable: métodos de pago, roles de
 * personal y permisos. NO crea usuarios, clientes, productos, equipos,
 * ventas, compras ni reservas, y no contiene ningún dato de la ferretería.
 *
 * El primer administrador se crea con un procedimiento seguro:
 *   php artisan sound:crear-administrador
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MetodoPagoSeeder::class,
            RolSeeder::class,
            PermisoSeeder::class,
        ]);
    }
}
