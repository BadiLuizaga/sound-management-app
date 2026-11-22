<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Models\CajaEvento;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Services\ArqueoService;
use Modules\Finance\Services\CajaService;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\Caja;
use Tests\TestCase;

/**
 * Etapa 3 — paso 5: ingresos y egresos manuales (D3) y movimientos
 * correctivos que preservan íntegramente el original (D4).
 */
class MovimientosCajaTest extends TestCase
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
    }

    private function servicio(): CajaService
    {
        return app(CajaService::class);
    }

    private function esperado(Caja $caja): string
    {
        return app(ArqueoService::class)->calcular($caja->fresh())->efectivoEsperado->decimal();
    }

    private function movimientoHttp($usuario, string $tipo, string $monto, array $extra = [])
    {
        return $this->actingAs($usuario)->post(route('caja.movimientos.store'), $extra + [
            'tipo' => $tipo,
            'monto' => $monto,
            'motivo' => 'Motivo de prueba',
            'clave_idempotencia' => (string) Str::uuid(),
        ]);
    }

    public function test_el_vendedor_registra_ingresos_de_cambio_pero_no_egresos(): void
    {
        $caja = $this->abrirCaja($this->vendedor, '100.00');

        $this->movimientoHttp($this->vendedor, 'ingreso', '20.00')->assertSessionHas('success');
        $this->movimientoHttp($this->vendedor, 'egreso', '5.00')->assertForbidden();

        $this->assertSame('120.00', $this->esperado($caja));
        $this->assertSame(1, MovimientoCaja::count());
        $this->assertTrue(CajaEvento::where('caja_id', $caja->id)->where('tipo', 'ingreso')->exists());
    }

    public function test_el_encargado_registra_egresos_sin_dejar_saldo_negativo(): void
    {
        $caja = $this->abrirCaja($this->encargado, '100.00');

        $this->movimientoHttp($this->encargado, 'egreso', '30.00')->assertSessionHas('success');
        $this->assertSame('70.00', $this->esperado($caja));

        $this->movimientoHttp($this->encargado, 'egreso', '70.01')->assertSessionHas('error');
        $this->assertSame('70.00', $this->esperado($caja));
        $this->assertSame(1, MovimientoCaja::count());
    }

    public function test_motivo_obligatorio_y_caja_abierta(): void
    {
        $this->movimientoHttp($this->encargado, 'ingreso', '5.00')->assertSessionHas('error'); // sin caja abierta
        $this->abrirCaja($this->encargado);
        $this->movimientoHttp($this->encargado, 'ingreso', '5.00', ['motivo' => ''])->assertSessionHasErrors('motivo');

        $this->assertSame(0, MovimientoCaja::count());
    }

    public function test_doble_envio_no_duplica_y_la_clave_no_se_comparte(): void
    {
        $this->abrirCaja($this->encargado);
        $this->abrirCaja($this->vendedor);
        $clave = (string) Str::uuid();

        $this->movimientoHttp($this->encargado, 'ingreso', '5.00', ['clave_idempotencia' => $clave])->assertSessionHas('success');
        $this->movimientoHttp($this->encargado, 'ingreso', '5.00', ['clave_idempotencia' => $clave])->assertSessionHas('success');
        $this->movimientoHttp($this->vendedor, 'ingreso', '5.00', ['clave_idempotencia' => $clave])->assertSessionHas('error');

        $this->assertSame(1, MovimientoCaja::count());
    }

    public function test_reversion_en_caja_abierta_preserva_el_original(): void
    {
        $caja = $this->abrirCaja($this->encargado, '100.00');
        $egreso = $this->servicio()->registrarMovimiento($this->encargado, 'egreso', Dinero::deDecimal('40', 'BOB'), 'Pago de flete', 'mov-flete-000000001');
        $original = DB::table('movimientos_caja')->where('id', $egreso->id)->first();

        // Solo el administrador revierte
        $this->actingAs($this->encargado)->post(route('caja.movimientos.revertir', $egreso), ['motivo' => 'x', 'clave_idempotencia' => (string) Str::uuid()])->assertForbidden();

        $this->abrirCaja($this->admin);
        $this->actingAs($this->admin)->post(route('caja.movimientos.revertir', $egreso), [
            'motivo' => 'El flete lo pagó el proveedor', 'clave_idempotencia' => (string) Str::uuid(),
        ])->assertSessionHas('success');

        $reversion = MovimientoCaja::where('revierte_id', $egreso->id)->firstOrFail();
        $this->assertSame('ingreso', $reversion->tipo);
        $this->assertSame('40.00', $reversion->monto);
        $this->assertSame($caja->id, $reversion->caja_id, 'la caja original sigue abierta: la reversión va en ella');
        $this->assertNull($reversion->caja_corregida_id);
        $this->assertEquals($original, DB::table('movimientos_caja')->where('id', $egreso->id)->first(), 'el original queda intacto');
        $this->assertSame('100.00', $this->esperado($caja));

        // No se revierte dos veces ni se revierte una reversión
        foreach ([$egreso, $reversion] as $m) {
            try {
                $this->servicio()->revertirMovimiento($m->fresh(), $this->admin, 'otra', 'rev-' . Str::random(20));
                $this->fail('Debía impedirse');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, MovimientoCaja::count());
    }

    public function test_reversion_de_una_caja_cerrada_va_en_otra_caja_y_la_referencia(): void
    {
        $cerrada = $this->abrirCaja($this->encargado, '100.00');
        $ingreso = $this->servicio()->registrarMovimiento($this->encargado, 'ingreso', Dinero::deDecimal('25', 'BOB'), 'Ingreso por error', 'mov-err-0000000001');
        $this->servicio()->cerrar($cerrada, $this->encargado, Dinero::deDecimal('125', 'BOB'), null, 'cierre-0000000000001');
        $foto = $cerrada->fresh()->resumen;

        try {
            $this->servicio()->revertirMovimiento($ingreso, $this->admin, 'Corrección', 'rev-sin-caja-000001');
            $this->fail('Sin caja abierta del responsable no hay dónde registrar la reversión');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $cajaAdmin = $this->abrirCaja($this->admin, '50.00');
        $rev = $this->servicio()->revertirMovimiento($ingreso, $this->admin, 'Corrección del ingreso', 'rev-con-caja-000001');

        $this->assertSame('egreso', $rev->tipo);
        $this->assertSame($cajaAdmin->id, $rev->caja_id);
        $this->assertSame($cerrada->id, $rev->caja_corregida_id);
        $this->assertSame($foto, $cerrada->fresh()->resumen, 'la foto de la caja cerrada no cambia');
        $this->assertSame(Caja::CERRADA, $cerrada->fresh()->estado);
        $this->assertTrue(CajaEvento::where('caja_id', $cerrada->id)->where('tipo', 'correccion_recibida')->exists());
        $this->assertSame('25.00', $this->esperado($cajaAdmin), '50 − 25');
    }

    public function test_correccion_referenciada_de_una_caja_cerrada(): void
    {
        $cerrada = $this->abrirCaja($this->vendedor, '100.00');
        $this->servicio()->cerrar($cerrada, $this->vendedor, Dinero::deDecimal('90', 'BOB'), 'Faltan 10', 'cierre-0000000000002');
        $this->abrirCaja($this->encargado, '50.00');
        $abiertaVendedor = $this->abrirCaja($this->vendedor);

        // El vendedor no registra correcciones (D3)
        $this->movimientoHttp($this->vendedor, 'ingreso', '10.00', ['caja_corregida' => $cerrada->id])->assertSessionHas('error');
        // Una caja abierta no se "corrige"
        $this->movimientoHttp($this->encargado, 'ingreso', '10.00', ['caja_corregida' => $abiertaVendedor->id])->assertSessionHas('error');

        $this->movimientoHttp($this->encargado, 'ingreso', '10.00', ['caja_corregida' => $cerrada->id, 'motivo' => 'Se encontraron los 10 Bs. faltantes'])->assertSessionHas('success');

        $mov = MovimientoCaja::firstOrFail();
        $this->assertSame($cerrada->id, $mov->caja_corregida_id);
        $this->assertSame('-10.00', $cerrada->fresh()->diferencia, 'la caja cerrada no se modifica');
        $this->actingAs($this->encargado)->get(route('caja.show', $cerrada))->assertOk()->assertSee('Se encontraron los 10 Bs. faltantes');
    }
}
