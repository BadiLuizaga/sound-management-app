<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\Rol;

/**
 * Catálogo de permisos y asignación por defecto a los roles del sistema.
 *
 * Fuente: config/access.php (la misma matriz que aplican hoy los Gates), para
 * que la base y el código no diverjan. La Etapa 3 hará de esta tabla la
 * fuente de verdad e incorporará las decisiones de caja, ajustes de
 * inventario, incidencias técnicas y visibilidad de ventas.
 *
 * Idempotente: crea los permisos que falten y añade las asignaciones por
 * defecto que falten (insertOrIgnore). No quita permisos asignados a mano.
 */
class PermisoSeeder extends Seeder
{
    private const DESCRIPCIONES = [
        'admin' => 'Acceso de administrador.',
        'almacenero' => 'Vista y gestión de almacén.',
        'staff' => 'Personal interno (acceso al panel).',
        'inventory.manage' => 'Crear, editar y dar de baja productos del catálogo.',
        'sales.operate' => 'Operar el punto de venta.',
        'customers.lookup' => 'Buscar clientes y registrarlos en mostrador.',
        'equipment.view' => 'Consultar el listado de equipos.',
        'rentals.view' => 'Consultar alquileres.',
        'rentals.operate' => 'Registrar alquileres y devoluciones.',
        'maintenance.manage' => 'Gestionar mantenimientos de equipos.',
        'reports.ai' => 'Reportes con IA (solo lectura).',
        'finance.payments.view_all' => 'Ver todos los pagos.',
        'finance.payments.view_own' => 'Ver los pagos de caja registrados por uno mismo.',
        'finance.payments.register' => 'Registrar cobros presenciales en caja.',
        'finance.payments.verify' => 'Verificar o rechazar transferencias y pagos QR.',
        'finance.payments.reconcile' => 'Conciliar pagos: reintentar o cerrar manualmente la aplicación.',
        'finance.refunds.request' => 'Solicitar reembolsos y confirmar su entrega en caja.',
        'finance.refunds.execute' => 'Autorizar y ejecutar reembolsos.',
        'finance.exchange.manage' => 'Registrar y modificar tipos de cambio.',
        'finance.audit.view' => 'Ver el historial financiero y los webhooks.',
    ];

    public function run(): void
    {
        $roles = (array) config('access.roles', []);
        $abilities = (array) config('access.abilities', []);
        $ahora = now();

        foreach ($abilities as $codigo => $rolesInternos) {
            if ($codigo === 'cliente') {
                continue; // el cliente no es un rol de personal (tipoPersona = 'C')
            }

            DB::table('permisos')->insertOrIgnore([
                'codigo' => $codigo,
                'descripcion' => self::DESCRIPCIONES[$codigo] ?? null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            $permisoId = DB::table('permisos')->where('codigo', $codigo)->value('id');

            foreach ((array) $rolesInternos as $interno) {
                // Nombre principal del rol en la tabla `rol` (el primero de la lista)
                $nombreRol = $roles[$interno][0] ?? null;
                $rol = $nombreRol ? Rol::where('nombre', $nombreRol)->first() : null;

                if (!$rol) {
                    continue;
                }

                DB::table('rol_permiso')->insertOrIgnore([
                    'id_rol' => $rol->id,
                    'permiso_id' => $permisoId,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }
    }
}
