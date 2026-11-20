<?php

namespace Tests\Feature\Instalacion;

use Database\Factories\UsuarioFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Models\Usuario;
use Tests\TestCase;

/**
 * Fase 2 — Etapa 1: registro de clientes desde el portal web en una base limpia.
 */
class RegistroClienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_cliente_crea_su_cuenta_desde_el_portal(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Email de 193 caracteres (antes la columna admitía 50 → error 500)
        $email = 'cliente@' . str_repeat('d', 60) . '.' . str_repeat('e', 60) . '.' . str_repeat('f', 60) . '.bo';
        $this->assertGreaterThan(150, strlen($email));

        $respuesta = $this->post('/register', [
            'ci' => 8800123,
            'nombre' => 'Cliente',
            'apellido' => 'Portal',
            'sexo' => 'F',
            'email' => $email,
            'password' => 'Cliente#2026x',
            'password_confirmation' => 'Cliente#2026x',
        ]);

        $respuesta->assertRedirect('/');
        $this->assertAuthenticated();

        $usuario = Usuario::find(8800123);
        $this->assertNotNull($usuario);
        $this->assertSame('C', $usuario->tipoPersona);
        $this->assertSame($email, $usuario->email);
        $this->assertNotNull($usuario->cliente);
        $this->assertNull($usuario->empleado);
    }

    public function test_el_cliente_registrado_no_accede_a_datos_de_otros_ni_al_panel(): void
    {
        $this->seed(DatabaseSeeder::class);
        $otro = UsuarioFactory::new()->cliente()->create();
        $cliente = UsuarioFactory::new()->cliente()->create();

        $this->actingAs($cliente);

        $this->get('/ventas')->assertForbidden();
        $this->getJson('/api/clientes/buscar/' . $otro->ci)->assertForbidden();
        $this->get('/alquileres')->assertForbidden();
        $this->get('/usuarios')->assertRedirect('/'); // middleware admin
        $this->get('/reportes-ia')->assertRedirect('/');
    }

    public function test_email_repetido_no_crea_una_segunda_cuenta(): void
    {
        $this->seed(DatabaseSeeder::class);
        $existente = UsuarioFactory::new()->cliente()->create(['email' => 'repetido@prueba.bo']);

        $this->post('/register', [
            'ci' => 8800999,
            'nombre' => 'Otro',
            'apellido' => 'Cliente',
            'sexo' => 'M',
            'email' => 'repetido@prueba.bo',
            'password' => 'Cliente#2026x',
            'password_confirmation' => 'Cliente#2026x',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, Usuario::where('email', 'repetido@prueba.bo')->count());
        $this->assertNotNull($existente->fresh());
    }
}
