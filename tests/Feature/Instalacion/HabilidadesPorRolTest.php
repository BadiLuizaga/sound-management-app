<?php

namespace Tests\Feature\Instalacion;

use Database\Factories\UsuarioFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regresión (Fase 2 — Etapa 2): las habilidades con punto ('sales.operate',
 * 'inventory.manage', ...) se denegaban a TODOS los usuarios porque config()
 * interpretaba el punto como clave anidada. Se comprueban casos POSITIVOS y
 * negativos de cada rol según config/access.php.
 */
class HabilidadesPorRolTest extends TestCase
{
    use RefreshDatabase;

    public static function casos(): array
    {
        return [
            'admin vende' => ['Administrador', 'sales.operate', true],
            'admin inventario' => ['Administrador', 'inventory.manage', true],
            'admin reportes IA' => ['Administrador', 'reports.ai', true],
            'admin alquileres' => ['Administrador', 'rentals.operate', true],
            'encargado vende' => ['Encargado', 'sales.operate', true],
            'encargado mantenimiento' => ['Encargado', 'maintenance.manage', true],
            'encargado sin reportes IA' => ['Encargado', 'reports.ai', false],
            'vendedor vende' => ['Vendedor', 'sales.operate', true],
            'vendedor busca clientes' => ['Vendedor', 'customers.lookup', true],
            'vendedor sin inventario' => ['Vendedor', 'inventory.manage', false],
            'tecnico mantenimiento' => ['Técnico', 'maintenance.manage', true],
            'tecnico ve alquileres' => ['Técnico', 'rentals.view', true],
            'tecnico no vende' => ['Técnico', 'sales.operate', false],
            'almacenero inventario' => ['Almacenero', 'inventory.manage', true],
            'almacenero no vende' => ['Almacenero', 'sales.operate', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casos')]
    public function test_habilidad_por_rol(string $rol, string $habilidad, bool $esperado): void
    {
        $this->seed(DatabaseSeeder::class);
        $usuario = UsuarioFactory::new()->conRol($rol)->create();

        $this->assertSame($esperado, Gate::forUser($usuario)->allows($habilidad), "{$rol} → {$habilidad}");
    }

    public function test_el_cliente_no_tiene_habilidades_de_personal(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cliente = UsuarioFactory::new()->cliente()->create();

        foreach (array_keys(config('access.abilities')) as $habilidad) {
            $this->assertSame($habilidad === 'cliente', Gate::forUser($cliente)->allows($habilidad), $habilidad);
        }
    }
}
