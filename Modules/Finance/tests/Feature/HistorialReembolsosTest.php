<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Enums\EstadoReembolso;
use Modules\Finance\Gateways\PaymentGateway;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Services\ConciliacionService;
use Modules\Finance\Services\ReembolsoService;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Tests\TestCase;

/**
 * Alcance de SOUND POS: no existe la gestión de reembolsos (solicitar,
 * autorizar, ejecutar ni entregar dinero). Se conservan:
 *  - el historial de reembolsos, en solo lectura;
 *  - el registro de los reembolsos hechos desde PayPal (WebhookPayPalTest);
 *  - la consulta de su estado en la conciliación de PayPal, sin reintentar
 *    nunca un reembolso (no hay ninguna operación que devuelva dinero).
 */
class HistorialReembolsosTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private PayPalFalso $paypal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->paypal = PayPalFalso::instalar();
        $this->tasaHoy('6.96');
    }

    /** Pago PayPal recibido de Bs. 200.00 (USD 28.74). */
    private function pagoPayPal(): Pago
    {
        $cliente = $this->cliente();
        $this->alCarrito($cliente, $this->producto(1, '100.00', 5), 2);
        $uuid = $this->actingAs($cliente)->postJson(route('finanzas.paypal.orden'), ['clave_idempotencia' => 'clave-historial-000001'])->json('uuid');
        $this->actingAs($cliente)->postJson(url("/pagos/{$uuid}/captura"))->assertOk();

        return Pago::where('uuid', $uuid)->firstOrFail();
    }

    /** Reembolso ya existente en el historial (anterior a SOUND POS). */
    private function historico(Pago $pago, string $estado, ?string $referencia, string $monto = '200.00'): Reembolso
    {
        return Reembolso::create([
            'uuid' => (string) Str::uuid(),
            'clave_idempotencia' => 'hist-' . Str::random(10),
            'pago_id' => $pago->id,
            'estado' => $estado,
            'origen' => Reembolso::ORIGEN_APLICACION,
            'monto' => $monto,
            'moneda' => 'BOB',
            'monto_proveedor' => '28.74',
            'moneda_proveedor' => 'USD',
            'referencia_externa' => $referencia,
            'motivo' => 'Reembolso histórico',
        ]);
    }

    public function test_no_existe_ninguna_operacion_de_reembolso(): void
    {
        foreach (['finanzas.admin.reembolsos.solicitar', 'finanzas.admin.reembolsos.autorizar', 'finanzas.admin.reembolsos.rechazar', 'finanzas.admin.reembolsos.confirmar-entrega'] as $ruta) {
            $this->assertFalse(Route::has($ruta), "La ruta {$ruta} no debe existir");
        }
        foreach (['finance.refunds.request', 'finance.refunds.execute'] as $permiso) {
            $this->assertArrayNotHasKey($permiso, config('access.abilities'));
        }
        foreach (['solicitar', 'autorizarYEjecutar', 'confirmarEntrega', 'rechazar'] as $metodo) {
            $this->assertFalse(method_exists(ReembolsoService::class, $metodo), "ReembolsoService::{$metodo} no debe existir");
        }
        $this->assertFalse(method_exists(PaymentGateway::class, 'reembolsarCaptura'), 'El gateway no debe poder ejecutar reembolsos');
        // Se comprueba el archivo, no class_exists(): el classmap optimizado de Composer
        // conserva la entrada hasta el próximo `composer dump-autoload`.
        $this->assertFileDoesNotExist(base_path('Modules/Finance/app/Http/Controllers/Admin/ReembolsoController.php'));

        $pago = $this->pagoPayPal();
        $r = $this->historico($pago, 'autorizado', null);
        $this->actingAs($this->admin)->post("/finanzas/pagos/{$pago->uuid}/reembolsos")->assertNotFound();
        $this->actingAs($this->admin)->post("/finanzas/reembolsos/{$r->uuid}/autorizar")->assertNotFound();
        $this->actingAs($this->admin)->post("/finanzas/reembolsos/{$r->uuid}/confirmar-entrega")->assertNotFound();
        $this->assertSame(0, $this->paypal->reembolsosSolicitados);
    }

    public function test_el_detalle_del_pago_muestra_el_historial_sin_acciones(): void
    {
        $pago = $this->pagoPayPal();
        $this->historico($pago, 'completado', 'REF-HIST-1', '50.00');

        $this->actingAs($this->admin)->get(route('finanzas.admin.pagos.show', $pago))
            ->assertOk()
            ->assertSee('Reembolsos registrados (historial)')
            ->assertSee('Reembolso histórico')
            ->assertDontSee('Solicitar reembolso')
            ->assertDontSee('Autorizar y ejecutar')
            ->assertDontSee('Confirmar entrega del dinero')
            ->assertDontSee('Disponible para reembolso');

        // Sin reembolsos, la sección no aparece
        $otro = Pago::whereKeyNot($pago->id)->first() ?? $pago;
        if ($otro->isNot($pago)) {
            $this->actingAs($this->admin)->get(route('finanzas.admin.pagos.show', $otro))->assertDontSee('Reembolsos registrados');
        }
    }

    public function test_la_conciliacion_solo_consulta_reembolsos_con_id_y_nunca_reembolsa(): void
    {
        $pago = $this->pagoPayPal();
        $conId = $this->historico($pago, 'incierto', 'REF-HIST-2');
        $sinId = $this->historico($pago, 'procesando', null, '0.01');

        $resumen = app(ConciliacionService::class)->ejecutar();

        // Con id de PayPal: se consulta su estado y se aplica
        $this->assertSame(EstadoReembolso::Completado, $conId->fresh()->estado);
        $this->assertSame(EstadoPago::Reembolsado, $pago->fresh()->estado);
        // Sin id: queda como está y jamás se reintenta
        $this->assertSame(EstadoReembolso::Procesando, $sinId->fresh()->estado);
        $this->assertSame(1, $resumen['reembolsos_resueltos']);
        $this->assertSame(1, $resumen['reembolsos_sin_id']);
        $this->assertSame(0, $this->paypal->reembolsosSolicitados);
    }

    public function test_el_historial_de_reembolsos_no_se_elimina(): void
    {
        $r = $this->historico($this->pagoPayPal(), 'completado', 'REF-HIST-3');

        $this->expectException(\LogicException::class);
        $r->delete();
    }
}
