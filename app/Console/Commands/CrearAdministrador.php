<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\Access\Models\Empleado;
use Modules\Access\Models\EstadoRol;
use Modules\Access\Models\Rol;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Instalación segura del PRIMER administrador de Sound Management.
 *
 *   php artisan sound:crear-administrador
 *
 * - Interactivo: la contraseña se escribe sin mostrarse y se confirma.
 *   Nunca se acepta por argumentos, variables de entorno ni archivos, para
 *   que no quede en el historial de la consola ni en los logs.
 * - Se niega a ejecutarse si ya existe un administrador activo: los
 *   siguientes administradores se crean desde la aplicación.
 * - Requiere que los seeders técnicos estén ejecutados (rol "Administrador").
 */
class CrearAdministrador extends Command
{
    protected $signature = 'sound:crear-administrador';

    protected $description = 'Crea el primer administrador del sistema (solo si todavía no existe ninguno)';

    public const ROL_ADMINISTRADOR = 'Administrador';

    public function handle(): int
    {
        if (!$this->input->isInteractive()) {
            $this->error('Este comando es interactivo: la contraseña no puede pasarse por argumentos ni en modo --no-interaction.');
            return self::FAILURE;
        }

        $rol = Rol::where('nombre', self::ROL_ADMINISTRADOR)->first();
        if (!$rol) {
            $this->error('No existe el rol "Administrador". Ejecuta primero: php artisan db:seed');
            return self::FAILURE;
        }

        if ($this->existeAdministradorActivo($rol)) {
            $this->error('Ya existe un administrador activo. Los nuevos administradores se crean desde la aplicación.');
            return self::FAILURE;
        }

        $this->info('Creación del primer administrador de Sound Management');

        $datos = [
            'ci' => trim(text(label: 'Cédula de identidad (solo números)', required: true)),
            'nombre' => trim(text(label: 'Nombre', required: true)),
            'apellido' => trim(text(label: 'Apellido', required: true)),
            'email' => strtolower(trim(text(label: 'Correo electrónico', required: true))),
            'password' => password(label: 'Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas, números y símbolos)', required: true),
            'password_confirmation' => password(label: 'Repite la contraseña', required: true),
        ];

        $validator = Validator::make($datos, [
            'ci' => ['required', 'integer', 'min:1', 'max:2147483647', 'unique:usuario,ci'],
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:usuario,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ], [], [
            'ci' => 'cédula',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
        ]);

        if ($validator->fails()) {
            $this->error('No se creó el administrador:');
            foreach ($validator->errors()->all() as $mensaje) {
                $this->line('  - ' . $mensaje);
            }
            return self::FAILURE;
        }

        $creado = DB::transaction(function () use ($datos, $rol) {
            // Comprobación repetida dentro de la transacción (dos ejecuciones simultáneas)
            if ($this->existeAdministradorActivo($rol, true)) {
                return false;
            }

            $usuario = Usuario::create([
                'ci' => (int) $datos['ci'],
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'email' => $datos['email'],
                'password' => Hash::make($datos['password']),
                'tipoPersona' => 'E',
            ]);

            Empleado::create([
                'ci' => $usuario->ci,
                'salario' => 0,
                'estado' => 'Activo',
            ]);

            EstadoRol::create([
                'id_rol' => $rol->id,
                'ci_empleado' => $usuario->ci,
                'fechaInicio' => now()->toDateString(),
                'estado' => 'Activo',
            ]);

            Bitacora::registrar(
                'INSTALACION',
                'usuario',
                (int) $usuario->ci,
                'Primer administrador creado con sound:crear-administrador.'
            );

            return true;
        });

        if (!$creado) {
            $this->error('Ya existe un administrador activo. No se creó ningún usuario.');
            return self::FAILURE;
        }

        $this->info('Administrador creado. Ya puedes iniciar sesión con su correo electrónico.');

        return self::SUCCESS;
    }

    private function existeAdministradorActivo(Rol $rol, bool $bloquear = false): bool
    {
        $consulta = EstadoRol::where('id_rol', $rol->id)->where('estado', 'Activo');

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->exists();
    }
}
