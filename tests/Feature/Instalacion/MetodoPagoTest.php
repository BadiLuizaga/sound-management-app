<?php

namespace Tests\Feature\Instalacion;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Fase 2 — Etapa 1: el sistema consulta el método de pago por código antes
 * de registrar un pago; nunca depende de un ID fijo.
 */
class MetodoPagoTest extends TestCase
{
    use RefreshDatabase;

    public function test_id_por_codigo_resuelve_el_metodo_sembrado(): void
    {
        $this->seed(DatabaseSeeder::class);

        $esperado = (int) MetodoPago::where('codigo', 'PAYPAL')->value('id');
        $this->assertSame($esperado, MetodoPago::idPorCodigo(MetodoPago::PAYPAL));
        $this->assertSame((int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), MetodoPago::idPorCodigo(MetodoPago::EFECTIVO));
    }

    public function test_metodo_inexistente_lanza_excepcion(): void
    {
        $this->expectException(\DomainException::class);
        MetodoPago::idPorCodigo(MetodoPago::PAYPAL); // base sin sembrar
    }

    public function test_metodo_desactivado_lanza_excepcion_y_no_esta_disponible(): void
    {
        $this->seed(DatabaseSeeder::class);
        MetodoPago::where('codigo', 'PAYPAL')->update(['activo' => false]);

        $this->assertFalse(MetodoPago::disponible(MetodoPago::PAYPAL));
        $this->expectException(\DomainException::class);
        MetodoPago::idPorCodigo(MetodoPago::PAYPAL);
    }

    public function test_metodos_presenciales_excluyen_paypal(): void
    {
        $this->seed(DatabaseSeeder::class);

        $codigos = MetodoPago::presenciales()->orderBy('id')->pluck('codigo')->all();
        $this->assertSame(['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR'], $codigos);
    }

    public function test_checkout_no_registra_venta_si_el_metodo_paypal_no_existe(): void
    {
        // PayPal "configurado" con valores ficticios, pero sin sembrar métodos de pago
        config(['services.paypal.client_id' => 'cliente-ficticio', 'services.paypal.secret' => 'secreto-ficticio']);
        $cliente = \Database\Factories\UsuarioFactory::new()->cliente()->create();

        $this->actingAs($cliente)
            ->post('/carrito/checkout', ['paypal_order_id' => 'ORDEN-FICTICIA-1'])
            ->assertRedirect(route('carrito.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('NotaVenta', 0);
        $this->assertDatabaseCount('pagos_externos', 0);
    }
}
