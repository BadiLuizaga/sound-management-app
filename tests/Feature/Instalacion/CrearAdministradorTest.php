<?php

namespace Tests\Feature\Instalacion;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Access\Models\Usuario;
use Tests\TestCase;

/**
 * Fase 2 — Etapa 1: procedimiento seguro para crear el primer administrador.
 * Todos los datos son ficticios y solo existen en la base de pruebas.
 */
class CrearAdministradorTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_VALIDA = 'Prueba-Segura#2026';

    private function ejecutar(array $datos)
    {
        return $this->artisan('sound:crear-administrador')
            ->expectsQuestion('Cédula de identidad (solo números)', $datos['ci'])
            ->expectsQuestion('Nombre', $datos['nombre'])
            ->expectsQuestion('Apellido', $datos['apellido'])
            ->expectsQuestion('Correo electrónico', $datos['email'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas, números y símbolos)', $datos['password'])
            ->expectsQuestion('Repite la contraseña', $datos['confirmacion']);
    }

    private function datosValidos(array $cambios = []): array
    {
        return array_merge([
            'ci' => '7001001',
            'nombre' => 'Ana',
            'apellido' => 'Prueba',
            'email' => 'Admin.Prueba@Sound.Test',
            'password' => self::CLAVE_VALIDA,
            'confirmacion' => self::CLAVE_VALIDA,
        ], $cambios);
    }

    public function test_crea_el_primer_administrador(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->ejecutar($this->datosValidos())->assertExitCode(0);

        $admin = Usuario::find(7001001);
        $this->assertNotNull($admin);
        $this->assertSame('E', $admin->tipoPersona);
        $this->assertSame('admin.prueba@sound.test', $admin->email);
        $this->assertNotSame(self::CLAVE_VALIDA, $admin->getAuthPassword());
        $this->assertTrue(Hash::check(self::CLAVE_VALIDA, $admin->getAuthPassword()));
        $this->assertNotNull($admin->empleado);
        $this->assertTrue(Gate::forUser($admin)->allows('admin'));
        $this->assertDatabaseHas('bitacoras', ['accion' => 'INSTALACION', 'registro_id' => 7001001]);
    }

    public function test_se_niega_si_ya_existe_un_administrador_activo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->ejecutar($this->datosValidos())->assertExitCode(0);

        $this->artisan('sound:crear-administrador')
            ->expectsOutputToContain('Ya existe un administrador activo')
            ->assertExitCode(1);

        $this->assertSame(1, Usuario::count());
    }

    public function test_rechaza_contrasena_debil(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->ejecutar($this->datosValidos(['password' => 'admin123', 'confirmacion' => 'admin123']))
            ->assertExitCode(1);

        $this->assertSame(0, Usuario::count());
    }

    public function test_rechaza_confirmacion_distinta(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->ejecutar($this->datosValidos(['confirmacion' => 'Otra-Clave#2026x']))
            ->assertExitCode(1);

        $this->assertSame(0, Usuario::count());
    }

    public function test_rechaza_email_invalido(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->ejecutar($this->datosValidos(['email' => 'no-es-un-correo']))
            ->assertExitCode(1);

        $this->assertSame(0, Usuario::count());
    }

    public function test_exige_seeders_tecnicos(): void
    {
        $this->artisan('sound:crear-administrador')
            ->expectsOutputToContain('php artisan db:seed')
            ->assertExitCode(1);
    }

    public function test_no_acepta_modo_no_interactivo(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('sound:crear-administrador', ['--no-interaction' => true])
            ->assertExitCode(1);

        $this->assertSame(0, Usuario::count());
    }
}
