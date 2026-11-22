<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Models\CajaEvento;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\CajaService;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Support\Dinero;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 3 — paso 3: apertura y cierre propios, cierre supervisado (D2),
 * observación ante diferencias (D5), pendientes al cierre (D6), sin
 * reaperturas (D4) y cajas heredadas intactas.
 */
class AperturaCierreCajaTest extends TestCase
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

    private function abrirPorHttp($usuario, string $fondo = '100.00')
    {
        return $this->actingAs($usuario)->post(route('caja.apertura'), ['monto_apertura' => $fondo]);
    }

    private function cerrarPorHttp($usuario, Caja $caja, string $contado, ?string $obs = null, ?string $clave = null)
    {
        return $this->actingAs($usuario)->post(route('caja.corte', $caja), [
            'efectivo_contado' => $contado,
            'observaciones' => $obs,
            'clave_cierre' => $clave ?? (string) Str::uuid(),
        ]);
    }

    private function venderEfectivo($cajero, string $clave, int $cantidad = 1)
    {
        return $this->actingAs($cajero)->postJson('/ventas', [
            'ci_cliente' => $this->cliente()->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'productos' => [['id' => 10, 'cantidad' => $cantidad]],
            'efectivo_recibido' => (string) (50 * $cantidad),
            'clave_idempotencia' => $clave,
        ]);
    }

    public function test_apertura_propia_por_rol(): void
    {
        foreach (['Administrador', 'Encargado', 'Vendedor'] as $rol) {
            $u = $this->usuarioConRol($rol);
            $this->abrirPorHttp($u)->assertRedirect(route('caja.index'))->assertSessionHas('success');
            $caja = Caja::where('user_id', $u->ci)->where('estado', 'abierta')->firstOrFail();
            $this->assertSame(Caja::ARQUEO_POR_METODO, $caja->version_arqueo);
            $this->assertTrue(CajaEvento::where('caja_id', $caja->id)->where('tipo', 'apertura')->where('ci_responsable', $u->ci)->exists());
        }
        foreach (['Técnico', 'Almacenero'] as $rol) {
            $this->abrirPorHttp($this->usuarioConRol($rol))->assertForbidden();
        }
        $this->abrirPorHttp($this->cliente())->assertForbidden();
        $this->assertSame(3, Caja::count());
    }

    public function test_no_se_abre_una_segunda_caja_propia(): void
    {
        $this->abrirPorHttp($this->vendedor)->assertSessionHas('success');
        $this->abrirPorHttp($this->vendedor)->assertSessionHas('error');

        $this->assertSame(1, Caja::where('user_id', $this->vendedor->ci)->count());
    }

    public function test_cierre_propio_guarda_la_foto_completa(): void
    {
        $this->abrirPorHttp($this->vendedor, '100.00');
        $this->venderEfectivo($this->vendedor, 'pos-cierre-propio-0001', 2)->assertOk();
        $caja = Caja::firstOrFail();

        $this->cerrarPorHttp($this->vendedor, $caja, '200.00')->assertSessionHas('success');

        $caja->refresh();
        $this->assertSame(Caja::CERRADA, $caja->estado);
        $this->assertSame('200.00', $caja->efectivo_esperado);
        $this->assertSame('200.00', $caja->efectivo_contado);
        $this->assertSame('0.00', $caja->diferencia);
        $this->assertSame('200.00', $caja->monto_cierre);
        $this->assertSame((int) $this->vendedor->ci, (int) $caja->ci_cerrado_por);
        $this->assertFalse($caja->cierre_supervisado);
        $this->assertSame('100.00', $caja->resumen['efectivo_cobrado']);
        $this->assertSame((int) $this->vendedor->ci, $caja->resumen['titular']);
        $this->assertTrue(CajaEvento::where('caja_id', $caja->id)->where('tipo', 'cierre')->exists());
    }

    public function test_el_cierre_es_idempotente_y_no_se_reabre(): void
    {
        $caja = app(CajaService::class)->abrir($this->vendedor, Dinero::deDecimal('50', 'BOB'));
        $clave = (string) Str::uuid();

        $this->cerrarPorHttp($this->vendedor, $caja, '50.00', null, $clave)->assertSessionHas('success');
        $fecha = $caja->fresh()->fecha_cierre;
        $eventos = CajaEvento::where('caja_id', $caja->id)->count();

        $this->cerrarPorHttp($this->vendedor, $caja, '50.00', null, $clave)->assertSessionHas('success'); // doble envío
        $this->assertEquals($fecha, $caja->fresh()->fecha_cierre);
        $this->assertSame($eventos, CajaEvento::where('caja_id', $caja->id)->count());

        $this->cerrarPorHttp($this->vendedor, $caja, '10.00', 'otro intento')->assertSessionHas('error');
        $this->assertSame('50.00', $caja->fresh()->efectivo_contado, 'una caja cerrada no cambia');

        // Sin reaperturas: una apertura nueva crea OTRA caja
        $this->abrirPorHttp($this->vendedor)->assertSessionHas('success');
        $this->assertSame(2, Caja::where('user_id', $this->vendedor->ci)->count());
        $this->assertSame(Caja::CERRADA, $caja->fresh()->estado);
    }

    public function test_diferencia_sin_observacion_se_rechaza(): void
    {
        $caja = app(CajaService::class)->abrir($this->vendedor, Dinero::deDecimal('100', 'BOB'));

        $this->cerrarPorHttp($this->vendedor, $caja, '95.00')->assertSessionHas('error');
        $this->assertSame(Caja::ABIERTA, $caja->fresh()->estado);

        $this->cerrarPorHttp($this->vendedor, $caja, '95.00', 'Faltan 5 Bs.')->assertSessionHas('success');
        $this->assertSame('-5.00', $caja->fresh()->diferencia);
        $this->assertSame('Faltan 5 Bs.', $caja->fresh()->observaciones);
    }

    public function test_cierre_supervisado_identifica_titular_y_responsable(): void
    {
        $caja = app(CajaService::class)->abrir($this->vendedor, Dinero::deDecimal('100', 'BOB'));

        // El vendedor no cierra cajas ajenas
        $otroVendedor = $this->usuarioConRol('Vendedor');
        $this->cerrarPorHttp($otroVendedor, $caja, '100.00', 'x')->assertForbidden();

        // Supervisado sin observación: rechazado
        $this->cerrarPorHttp($this->encargado, $caja, '100.00')->assertSessionHas('error');
        $this->assertSame(Caja::ABIERTA, $caja->fresh()->estado);

        $this->cerrarPorHttp($this->encargado, $caja, '100.00', 'El vendedor se retiró sin cerrar')->assertSessionHas('success');

        $caja->refresh();
        $this->assertTrue($caja->cierre_supervisado);
        $this->assertSame((int) $this->vendedor->ci, (int) $caja->user_id, 'el titular no cambia');
        $this->assertSame((int) $this->encargado->ci, (int) $caja->ci_cerrado_por);
        $evento = CajaEvento::where('caja_id', $caja->id)->where('tipo', 'cierre_supervisado')->firstOrFail();
        $this->assertSame((int) $this->vendedor->ci, (int) $evento->ci_titular);
        $this->assertSame((int) $this->encargado->ci, (int) $evento->ci_responsable);
        $this->assertTrue($caja->resumen['cierre_supervisado']);
    }

    public function test_el_administrador_tambien_cierra_cajas_ajenas(): void
    {
        $caja = app(CajaService::class)->abrir($this->encargado, Dinero::deDecimal('10', 'BOB'));

        $this->cerrarPorHttp($this->admin, $caja, '10.00', 'Cierre de fin de turno')->assertSessionHas('success');

        $this->assertTrue($caja->fresh()->cierre_supervisado);
        $this->assertSame((int) $this->admin->ci, (int) $caja->fresh()->ci_cerrado_por);
    }

    public function test_un_cobro_despues_del_cierre_se_rechaza(): void
    {
        $caja = app(CajaService::class)->abrir($this->vendedor, Dinero::deDecimal('100', 'BOB'));
        $this->cerrarPorHttp($this->vendedor, $caja, '100.00')->assertSessionHas('success');

        $this->venderEfectivo($this->vendedor, 'pos-tras-cierre-000001')->assertStatus(422);

        $this->assertSame(0, Pago::count());
    }

    public function test_cierre_con_transferencia_pendiente_y_verificacion_posterior_no_altera_la_foto(): void
    {
        $caja = app(CajaService::class)->abrir($this->vendedor, Dinero::deDecimal('100', 'BOB'));
        $this->actingAs($this->vendedor)->postJson('/ventas', [
            'ci_cliente' => $this->cliente()->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::TRANSFERENCIA),
            'productos' => [['id' => 10, 'cantidad' => 1]],
            'referencia' => 'TRF-998877',
            'clave_idempotencia' => 'pos-trf-pendiente-0001',
        ])->assertOk()->assertJson(['pendiente' => true]);

        $this->cerrarPorHttp($this->vendedor, $caja, '100.00')->assertSessionHas('success');
        $foto = $caja->fresh()->resumen;
        $this->assertCount(1, $foto['pendientes']);
        $this->assertSame('50.00', $foto['metodos']['TRANSFERENCIA']['pendiente']);
        $this->assertSame('100.00', $foto['efectivo_esperado'], 'la transferencia no es efectivo');

        app(PagoService::class)->verificar(Pago::firstOrFail(), $this->encargado);

        $this->assertSame($foto, $caja->fresh()->resumen, 'la foto del cierre no cambia');
        $this->assertSame('recibido', Pago::firstOrFail()->estado->value);
    }

    public function test_cajas_heredadas_conservan_importes_y_su_pdf(): void
    {
        $id = DB::table('cajas')->insertGetId([
            'user_id' => $this->vendedor->ci, 'monto_apertura' => '100.00', 'monto_cierre' => '80.00',
            'diferencia' => '-20.00', 'estado' => 'cerrada', 'fecha_apertura' => now()->subDay(),
            'fecha_cierre' => now()->subDay()->addHours(8), 'version_arqueo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $antes = (array) DB::table('cajas')->where('id', $id)->first();

        $this->actingAs($this->admin)->get(route('caja.show', $id))->assertOk()->assertSee('arqueo anterior a la Etapa 3');
        $this->actingAs($this->admin)->get(route('caja.reporte', $id))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertEquals($antes, (array) DB::table('cajas')->where('id', $id)->first());
    }

    public function test_pdf_del_corte_por_metodo(): void
    {
        $caja = app(CajaService::class)->abrir($this->vendedor, Dinero::deDecimal('100', 'BOB'));
        $this->venderEfectivo($this->vendedor, 'pos-pdf-corte-0000001')->assertOk();
        $this->cerrarPorHttp($this->vendedor, $caja, '150.00')->assertSessionHas('success');

        $this->actingAs($this->vendedor)->get(route('caja.reporte', $caja))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->usuarioConRol('Vendedor'))->get(route('caja.reporte', $caja))->assertForbidden();
    }

    public function test_el_vendedor_solo_ve_sus_cajas(): void
    {
        $mia = app(CajaService::class)->abrir($this->vendedor, Dinero::deDecimal('1', 'BOB'));
        $ajena = app(CajaService::class)->abrir($this->encargado, Dinero::deDecimal('2', 'BOB'));

        $this->actingAs($this->vendedor)->get(route('caja.index'))->assertOk()
            ->assertSee(route('caja.show', $mia), false)
            ->assertDontSee(route('caja.show', $ajena), false);
        $this->actingAs($this->vendedor)->get(route('caja.show', $ajena))->assertForbidden();

        $this->actingAs($this->encargado)->get(route('caja.index'))->assertOk()
            ->assertSee(route('caja.show', $mia), false);
    }

    public function test_tecnico_y_almacenero_no_acceden_a_caja(): void
    {
        foreach (['Técnico', 'Almacenero'] as $rol) {
            $this->actingAs($this->usuarioConRol($rol))->get(route('caja.index'))->assertForbidden();
        }
    }
}
