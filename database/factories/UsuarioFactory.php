<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Modules\Access\Models\Cliente;
use Modules\Access\Models\Empleado;
use Modules\Access\Models\EstadoRol;
use Modules\Access\Models\Rol;
use Modules\Access\Models\Usuario;

/**
 * Usuarios ficticios SOLO para pruebas automatizadas (nunca en seeders).
 *
 * Uso: UsuarioFactory::new()->cliente()->create();
 *      UsuarioFactory::new()->conRol('Encargado')->create();
 *
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'ci' => $this->faker->unique()->numberBetween(1000000, 99999999),
            'nombre' => $this->faker->firstName(),
            'apellido' => $this->faker->lastName(),
            'telefono' => null,
            'sexo' => $this->faker->randomElement(['M', 'F']),
            'email' => $this->faker->unique()->safeEmail(),
            'domicilio' => null,
            'tipoPersona' => 'C',
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    /**
     * Cliente registrado (tipoPersona 'C' + fila en `cliente`).
     */
    public function cliente(): static
    {
        return $this->state(fn () => ['tipoPersona' => 'C'])
            ->afterCreating(function (Usuario $usuario) {
                Cliente::firstOrCreate(['ci' => $usuario->ci], ['categoria' => null]);
            });
    }

    /**
     * Empleado con un rol activo (el rol debe existir: RolSeeder o creado en la prueba).
     */
    public function conRol(string $nombreRol): static
    {
        return $this->state(fn () => ['tipoPersona' => 'E'])
            ->afterCreating(function (Usuario $usuario) use ($nombreRol) {
                Empleado::firstOrCreate(['ci' => $usuario->ci], ['salario' => 0, 'estado' => 'Activo']);

                $rol = Rol::where('nombre', $nombreRol)->firstOrFail();

                EstadoRol::create([
                    'id_rol' => $rol->id,
                    'ci_empleado' => $usuario->ci,
                    'fechaInicio' => now()->toDateString(),
                    'estado' => 'Activo',
                ]);
            });
    }
}
