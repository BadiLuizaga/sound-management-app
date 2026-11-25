<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\CajaEvento;
use Modules\Finance\Services\CajaService;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 3 — paso 6: historial y auditoría de aperturas, cierres, ingresos,
 * egresos, reembolsos y responsables.
 */
class AuditoriaCajaTest extends TestCase
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

    private function cajas(): CajaService
    {
        return app(CajaService::class);
    }

    public function test_historial_completo_con_titular_y_responsables(): void
    {
        $caja = $this->cajas()->abrir($this->vendedor, Dinero::deDecimal('100', 'BOB'));
        $this->cajas()->registrarMovimiento($this->vendedor, 'ingreso', Dinero::deDecimal('20', 'BOB'), 'Cambio adicional', 'mov-aud-0000000001');

        $this->actingAs($this->vendedor)->postJson('/ventas', [
            'ci_cliente' => $this->cliente()->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'productos' => [['id' => 10, 'cantidad' => 1]],
            'efectivo_recibido' => '50',
            'clave_idempotencia' => 'pos-auditoria-000001',
        ])->assertOk();

        $this->cajas()->cerrar($caja, $this->encargado, Dinero::deDecimal('170', 'BOB'), 'Cierre por cambio de turno', 'cierre-aud-0000001');

        $eventos = CajaEvento::where('caja_id', $caja->id)->orderBy('id')->get();
        $this->assertSame(['apertura', 'ingreso', 'cierre_supervisado'], $eventos->pluck('tipo')->all());
        $this->assertSame([(int) $this->vendedor->ci], $eventos->pluck('ci_titular')->map(fn ($v) => (int) $v)->unique()->values()->all(), 'el titular es siempre el mismo');
        $this->assertSame(
            [(int) $this->vendedor->ci, (int) $this->vendedor->ci, (int) $this->encargado->ci],
            $eventos->pluck('ci_responsable')->map(fn ($v) => (int) $v)->all()
        );
        $this->assertSame('170.00', $eventos->last()->datos['efectivo_contado']);
        $this->assertSame('0.00', $eventos->last()->datos['diferencia'], '100 + 20 + 50');

        $this->actingAs($this->encargado)->get(route('caja.show', $caja))->assertOk()
            ->assertSee('cierre supervisado')
            ->assertSee($this->encargado->nombre)
            ->assertSee('Cierre por cambio de turno');
    }

    public function test_la_bitacora_general_registra_aperturas_cierres_y_movimientos(): void
    {
        $caja = $this->cajas()->abrir($this->encargado, Dinero::deDecimal('100', 'BOB'));
        $mov = $this->cajas()->registrarMovimiento($this->encargado, 'egreso', Dinero::deDecimal('15', 'BOB'), 'Compra de cinta', 'mov-aud-0000000002');
        $this->cajas()->cerrar($caja, $this->encargado, Dinero::deDecimal('85', 'BOB'), null, 'cierre-aud-0000002');

        $this->assertTrue(DB::table('bitacoras')->where('accion', 'CAJA_APERTURA')->where('registro_id', $caja->id)->exists());
        $this->assertTrue(DB::table('bitacoras')->where('accion', 'CAJA_EGRESO')->where('registro_id', $mov->id)->exists());
        $this->assertTrue(DB::table('bitacoras')->where('accion', 'CAJA_CIERRE')->where('registro_id', $caja->id)->exists());
    }

    public function test_el_historial_de_caja_es_de_solo_insercion(): void
    {
        $caja = $this->cajas()->abrir($this->vendedor, Dinero::deDecimal('1', 'BOB'));
        $evento = CajaEvento::where('caja_id', $caja->id)->firstOrFail();

        foreach ([fn () => $evento->update(['tipo' => 'otro']), fn () => $evento->delete()] as $accion) {
            try {
                $accion();
                $this->fail('Debía impedirse');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('apertura', $evento->fresh()->tipo);
    }

    public function test_un_intento_de_cerrar_una_caja_ajena_queda_registrado(): void
    {
        $caja = $this->cajas()->abrir($this->encargado, Dinero::deDecimal('1', 'BOB'));

        try {
            $this->cajas()->cerrar($caja, $this->vendedor, Dinero::deDecimal('1', 'BOB'), 'x', 'cierre-intento-0001');
            $this->fail('El vendedor no puede cerrar cajas ajenas');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $intento = CajaEvento::where('caja_id', $caja->id)->where('tipo', 'intento_rechazado')->firstOrFail();
        $this->assertSame((int) $this->vendedor->ci, (int) $intento->ci_responsable);
        $this->assertSame((int) $this->encargado->ci, (int) $intento->ci_titular);
        $this->assertSame('abierta', $caja->fresh()->estado);
    }
}
