<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Finance\Models\TipoCambio;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Services\TipoCambioService;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Tests\TestCase;

/**
 * Etapa 2 — paso 3: tipo de cambio diario, explícito y sin tasa por defecto.
 */
class TipoCambioServicioTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
    }

    public function test_sin_tasa_del_dia_no_hay_pago_en_linea(): void
    {
        PayPalFalso::instalar();

        $disp = app(PagoService::class)->disponibilidadEnLinea();
        $this->assertFalse($disp['disponible']);
        $this->assertStringContainsString('tipo de cambio', $disp['motivo']);
    }

    public function test_la_tasa_de_ayer_no_se_hereda(): void
    {
        PayPalFalso::instalar();
        $svc = app(TipoCambioService::class);
        $svc->registrar($this->admin, '6.96', $svc->hoy()->copy()->subDay()->toDateString());

        $this->assertNull($svc->vigenteParaHoy());
        $this->assertFalse(app(PagoService::class)->disponibilidadEnLinea()['disponible']);
    }

    public function test_con_tasa_del_dia_y_configuracion_completa_esta_disponible(): void
    {
        PayPalFalso::instalar();
        $this->tasaHoy('6.96');

        $disp = app(PagoService::class)->disponibilidadEnLinea();
        $this->assertTrue($disp['disponible']);
        $this->assertSame('14.37', app(TipoCambioService::class)->convertir(Dinero::deDecimal('100', 'BOB'), $disp['tasa'])->decimal());
    }

    public function test_configuracion_incompleta_no_esta_disponible(): void
    {
        PayPalFalso::instalar();
        $this->tasaHoy();
        config(['services.paypal.webhook_id' => null]);

        $this->assertFalse(app(PagoService::class)->disponibilidadEnLinea()['disponible']);
    }

    public function test_una_tasa_por_dia(): void
    {
        $svc = app(TipoCambioService::class);
        $svc->registrar($this->admin, '6.96', '2026-11-20');

        $this->expectException(\DomainException::class);
        $svc->registrar($this->admin, '7.00', '2026-11-20');
    }

    public function test_modificar_tasa_no_usada_deja_revision(): void
    {
        $svc = app(TipoCambioService::class);
        $tc = $svc->registrar($this->admin, '6.96', '2026-11-21');

        $svc->modificar($tc, '6.97', 'Corrección de digitación', $this->admin);

        $this->assertSame('6.970000', TipoCambio::find($tc->id)->tasa);
        $this->assertDatabaseHas('tipo_cambio_revisiones', ['tipo_cambio_id' => $tc->id, 'motivo' => 'Corrección de digitación']);
    }

    public function test_tasa_invalida_rechazada(): void
    {
        $this->expectException(\DomainException::class);
        app(TipoCambioService::class)->registrar($this->admin, '0', '2026-11-22');
    }

    public function test_fecha_de_aplicacion_usa_la_zona_de_la_paz(): void
    {
        // 02:00 UTC del día 21 = 22:00 del día 20 en La Paz
        Carbon::setTestNow(Carbon::parse('2026-11-21 02:00:00', 'UTC'));
        $this->assertSame('2026-11-20', app(TipoCambioService::class)->hoy()->toDateString());
        Carbon::setTestNow();
    }
}
