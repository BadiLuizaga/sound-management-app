<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Models\CajaEvento;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Services\ArqueoService;
use Modules\Finance\Services\ReembolsoService;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 3 — paso 4: reembolsos integrados al arqueo (D7). Solo el efectivo
 * resta, y sale de la caja abierta de quien lo entrega.
 */
class ReembolsoEnCajaTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private $vendedor;
    private $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        PayPalFalso::instalar();
        $this->vendedor = $this->usuarioConRol('Vendedor');
        $this->encargado = $this->usuarioConRol('Encargado');
        $this->producto(10, '50.00', 20);
    }

    private function servicio(): ReembolsoService
    {
        return app(ReembolsoService::class);
    }

    private function esperado($caja): string
    {
        return app(ArqueoService::class)->calcular($caja->fresh())->efectivoEsperado->decimal();
    }

    /** Venta del vendedor en su caja + reembolso autorizado de $monto. */
    private function reembolsoAutorizado(string $metodo, string $monto, array $extra = []): Reembolso
    {
        $this->abrirCaja($this->vendedor, '100.00');
        $this->actingAs($this->vendedor)->postJson('/ventas', [
            'ci_cliente' => $this->cliente()->ci,
            'id_pago' => MetodoPago::idPorCodigo($metodo),
            'productos' => [['id' => 10, 'cantidad' => 2]],
            'clave_idempotencia' => 'pos-reemb-caja-' . Str::random(10),
        ] + $extra)->assertOk();
        $pago = Pago::latest('id')->firstOrFail();

        $r = $this->servicio()->solicitar($pago, Dinero::deDecimal($monto, 'BOB'), 'Devolución', $this->encargado, 'reemb-caja-' . Str::random(10));

        return $this->servicio()->autorizarYEjecutar($r, $this->admin);
    }

    public function test_en_efectivo_sin_caja_propia_abierta_se_rechaza(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::EFECTIVO, '30.00', ['efectivo_recibido' => '100']);

        try {
            $this->servicio()->confirmarEntrega($r, $this->encargado);
            $this->fail('Debía exigir la caja abierta');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('caja abierta', $e->getMessage());
        }

        $r->refresh();
        $this->assertSame(EstadoReembolso::Autorizado, $r->estado);
        $this->assertNull($r->caja_id);
    }

    public function test_en_efectivo_sale_de_la_caja_de_quien_entrega(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::EFECTIVO, '30.00', ['efectivo_recibido' => '100']);
        $cajaVendedor = $this->abrirCaja($this->vendedor);
        $cajaEncargado = $this->abrirCaja($this->encargado, '50.00');
        $this->assertSame('200.00', $this->esperado($cajaVendedor));

        $r = $this->servicio()->confirmarEntrega($r, $this->encargado);

        $this->assertSame(EstadoReembolso::Completado, $r->estado);
        $this->assertSame(Reembolso::MEDIO_EFECTIVO, $r->medio_devolucion);
        $this->assertSame($cajaEncargado->id, $r->caja_id);
        $this->assertSame((int) $this->encargado->ci, (int) $r->ci_entregado_por);
        $this->assertNotNull($r->entregado_at);
        $this->assertSame('20.00', $this->esperado($cajaEncargado), '50 − 30');
        $this->assertSame('200.00', $this->esperado($cajaVendedor), 'la caja del cobro no cambia');
        $this->assertTrue(CajaEvento::where('caja_id', $cajaEncargado->id)->where('tipo', 'reembolso_entregado')->exists());
    }

    public function test_repetir_la_confirmacion_no_resta_dos_veces(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::EFECTIVO, '30.00', ['efectivo_recibido' => '100']);
        $caja = $this->abrirCaja($this->vendedor);

        $this->servicio()->confirmarEntrega($r, $this->vendedor);
        $eventos = CajaEvento::where('caja_id', $caja->id)->count();
        $this->servicio()->confirmarEntrega($r->fresh(), $this->vendedor);

        $this->assertSame('170.00', $this->esperado($caja), '100 + 100 − 30');
        $this->assertSame($eventos, CajaEvento::where('caja_id', $caja->id)->count());
    }

    public function test_por_tarjeta_no_resta_efectivo_ni_exige_caja(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::TARJETA, '40.00', ['referencia' => 'AUT-4455']);
        $cajaVendedor = $this->abrirCaja($this->vendedor);

        $r = $this->servicio()->confirmarEntrega($r, $this->encargado); // el encargado no tiene caja

        $this->assertSame(EstadoReembolso::Completado, $r->estado);
        $this->assertSame(Reembolso::MEDIO_TARJETA, $r->medio_devolucion, 'por defecto, el mismo método del cobro');
        $this->assertNull($r->caja_id);
        $this->assertSame('100.00', $this->esperado($cajaVendedor));
    }

    public function test_medio_distinto_del_original_se_respeta(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::TARJETA, '40.00', ['referencia' => 'AUT-7788']);
        $caja = $this->abrirCaja($this->vendedor);

        $r = $this->servicio()->confirmarEntrega($r, $this->vendedor, Reembolso::MEDIO_EFECTIVO);

        $this->assertSame($caja->id, $r->caja_id);
        $this->assertSame('60.00', $this->esperado($caja), 'tarjeta cobrada no suma; el efectivo devuelto sí resta');
    }

    public function test_no_se_entrega_mas_efectivo_del_que_hay_en_la_caja(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::TARJETA, '90.00', ['referencia' => 'AUT-9900']);
        $caja = $this->abrirCaja($this->vendedor); // fondo 100, sin efectivo cobrado

        $this->servicio()->confirmarEntrega($r, $this->vendedor, Reembolso::MEDIO_EFECTIVO); // 90 ≤ 100: permitido
        $this->assertSame('10.00', $this->esperado($caja));

        $otro = $this->servicio()->solicitar($r->pago->fresh(), Dinero::deDecimal('10.00', 'BOB'), 'Resto', $this->encargado, 'reemb-resto-0000001');
        $otro = $this->servicio()->autorizarYEjecutar($otro, $this->admin);
        $this->servicio()->confirmarEntrega($otro, $this->vendedor, Reembolso::MEDIO_EFECTIVO); // 10 ≤ 10
        $this->assertSame('0.00', $this->esperado($caja));
    }

    public function test_supera_el_efectivo_esperado_se_rechaza(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::TARJETA, '90.00', ['referencia' => 'AUT-1212']);
        $this->abrirCaja($this->encargado, '20.00');

        $this->expectException(\DomainException::class);
        $this->servicio()->confirmarEntrega($r, $this->encargado, Reembolso::MEDIO_EFECTIVO);
    }

    public function test_confirmacion_por_http_con_medio_y_permisos(): void
    {
        $r = $this->reembolsoAutorizado(MetodoPago::EFECTIVO, '30.00', ['efectivo_recibido' => '100']);
        $cajaEncargado = $this->abrirCaja($this->encargado, '50.00');

        $this->actingAs($this->vendedor)->post(route('finanzas.admin.reembolsos.confirmar-entrega', $r), ['medio_devolucion' => 'efectivo'])->assertForbidden();
        $this->actingAs($this->encargado)->post(route('finanzas.admin.reembolsos.confirmar-entrega', $r), ['medio_devolucion' => 'paypal'])->assertSessionHasErrors('medio_devolucion');
        $this->assertSame(EstadoReembolso::Autorizado, $r->fresh()->estado);

        $this->actingAs($this->encargado)->post(route('finanzas.admin.reembolsos.confirmar-entrega', $r), ['medio_devolucion' => 'efectivo'])->assertSessionHas('success');
        $this->assertSame($cajaEncargado->id, $r->fresh()->caja_id);
    }
}
