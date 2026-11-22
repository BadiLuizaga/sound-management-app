<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Services\ArqueoService;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 3 — paso 2: fórmula del arqueo por caja_id y método de pago
 * (regresión de E1, E2 y E5 del diagnóstico).
 */
class ArqueoCajaTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private $cajero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        PayPalFalso::instalar();
        $this->cajero = $this->usuarioConRol('Vendedor');
    }

    private function arqueo(Caja $caja)
    {
        return app(ArqueoService::class)->calcular($caja->fresh());
    }

    private function pago(?Caja $caja, string $metodo, string $monto, string $estado = 'recibido', string $canal = 'presencial'): Pago
    {
        return Pago::create([
            'uuid' => (string) Str::uuid(),
            'canal' => $canal,
            'proveedor' => $metodo === MetodoPago::PAYPAL ? 'paypal' : 'manual',
            'metodo_pago_id' => MetodoPago::idPorCodigo($metodo),
            'estado' => $estado,
            'estado_aplicacion' => 'aplicado',
            'monto' => $monto,
            'moneda' => 'BOB',
            'referencia_externa' => in_array($metodo, [MetodoPago::EFECTIVO, MetodoPago::PAYPAL], true) ? null : 'REF-' . Str::random(10),
            'ci_registrado_por' => $caja ? $caja->user_id : null,
            'caja_id' => $caja?->id,
            'snapshot' => ['tipo' => 'venta_presencial', 'items' => []],
        ]);
    }

    private function reembolso(Pago $pago, ?Caja $caja, string $monto, string $medio, string $estado = 'completado'): Reembolso
    {
        return Reembolso::create([
            'uuid' => (string) Str::uuid(),
            'clave_idempotencia' => 'arqueo-' . Str::random(12),
            'pago_id' => $pago->id,
            'estado' => $estado,
            'origen' => 'aplicacion',
            'monto' => $monto,
            'moneda' => 'BOB',
            'motivo' => 'Prueba',
            'caja_id' => $caja?->id,
            'medio_devolucion' => $medio,
            'completado_at' => $estado === 'completado' ? now() : null,
        ]);
    }

    private function movimiento(Caja $caja, string $tipo, string $monto): MovimientoCaja
    {
        return MovimientoCaja::create([
            'uuid' => (string) Str::uuid(),
            'clave_idempotencia' => 'mov-' . Str::random(12),
            'caja_id' => $caja->id,
            'tipo' => $tipo,
            'monto' => $monto,
            'moneda' => 'BOB',
            'motivo' => 'Prueba',
            'ci_registrado_por' => $caja->user_id,
        ]);
    }

    public function test_mezcla_de_metodos_solo_el_efectivo_entra_al_esperado(): void
    {
        $caja = $this->abrirCaja($this->cajero, '100.00');
        $this->pago($caja, MetodoPago::EFECTIVO, '100.00');
        $this->pago($caja, MetodoPago::TARJETA, '50.00');
        $this->pago($caja, MetodoPago::TRANSFERENCIA, '30.00');                       // verificada
        $this->pago($caja, MetodoPago::QR, '20.00', 'pendiente_verificacion');
        $this->pago(null, MetodoPago::PAYPAL, '40.00', 'recibido', 'en_linea');       // en línea: sin caja

        $a = $this->arqueo($caja);

        $this->assertSame('200.00', $a->efectivoEsperado->decimal(), 'fondo 100 + efectivo 100');
        $this->assertSame('100.00', $a->metodos[MetodoPago::EFECTIVO]['recibido']->decimal());
        $this->assertSame('50.00', $a->metodos[MetodoPago::TARJETA]['recibido']->decimal());
        $this->assertSame('30.00', $a->metodos[MetodoPago::TRANSFERENCIA]['recibido']->decimal());
        $this->assertSame('0.00', $a->metodos[MetodoPago::QR]['recibido']->decimal());
        $this->assertSame('20.00', $a->metodos[MetodoPago::QR]['pendiente']->decimal());
        $this->assertSame(1, $a->metodos[MetodoPago::QR]['pendientes']);
        $this->assertSame('0.00', $a->metodos[MetodoPago::PAYPAL]['recibido']->decimal());
        $this->assertCount(1, $a->pendientes);
    }

    public function test_el_cambio_entregado_no_infla_el_efectivo(): void
    {
        $caja = $this->abrirCaja($this->cajero, '50.00');
        $this->producto(10, '100.00', 5);
        $this->actingAs($this->cajero)->postJson('/ventas', [
            'ci_cliente' => $this->cliente()->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'productos' => [['id' => 10, 'cantidad' => 1]],
            'efectivo_recibido' => '150',
            'clave_idempotencia' => 'pos-arqueo-cambio-0001',
        ])->assertOk();

        $this->assertSame('150.00', $this->arqueo($caja)->efectivoEsperado->decimal(), 'fondo 50 + venta 100 (los 50 de cambio salieron)');
    }

    public function test_solo_los_reembolsos_en_efectivo_completados_de_la_caja_restan(): void
    {
        $caja = $this->abrirCaja($this->cajero, '100.00');
        $otraCaja = $this->abrirCaja($this->usuarioConRol('Encargado'));
        $efectivo = $this->pago($caja, MetodoPago::EFECTIVO, '200.00', 'reembolsado_parcial');
        $tarjeta = $this->pago($caja, MetodoPago::TARJETA, '90.00', 'reembolsado_parcial');

        $this->reembolso($efectivo, $caja, '25.00', Reembolso::MEDIO_EFECTIVO);
        $this->reembolso($tarjeta, $caja, '10.00', Reembolso::MEDIO_TARJETA);           // por terminal: no es efectivo
        $this->reembolso($efectivo, $otraCaja, '5.00', Reembolso::MEDIO_EFECTIVO);      // salió de otra caja
        $this->reembolso($efectivo, $caja, '7.00', Reembolso::MEDIO_EFECTIVO, 'autorizado'); // aún no entregado

        $a = $this->arqueo($caja);

        $this->assertSame('275.00', $a->efectivoEsperado->decimal(), '100 + 200 − 25');
        $this->assertSame('25.00', $a->reembolsosEfectivo->decimal());
        $this->assertSame('10.00', $a->reembolsosPorMedio[Reembolso::MEDIO_TARJETA]['monto']->decimal());
        $this->assertSame('95.00', $this->arqueo($otraCaja)->efectivoEsperado->decimal(), 'la otra caja (fondo 100) entregó 5');
    }

    public function test_pago_reembolsado_no_se_descuenta_dos_veces(): void
    {
        $caja = $this->abrirCaja($this->cajero, '100.00');
        $pago = $this->pago($caja, MetodoPago::EFECTIVO, '80.00', 'reembolsado');
        $this->reembolso($pago, $caja, '80.00', Reembolso::MEDIO_EFECTIVO);

        $a = $this->arqueo($caja);

        $this->assertSame('100.00', $a->efectivoEsperado->decimal(), '100 + 80 − 80');
        $this->assertSame('80.00', $a->efectivoCobrado->decimal());
    }

    public function test_ingresos_y_egresos_manuales(): void
    {
        $caja = $this->abrirCaja($this->cajero, '100.00');
        $this->movimiento($caja, MovimientoCaja::INGRESO, '10.00');
        $this->movimiento($caja, MovimientoCaja::EGRESO, '15.00');

        $a = $this->arqueo($caja);

        $this->assertSame('95.00', $a->efectivoEsperado->decimal());
        $this->assertSame('10.00', $a->ingresos->decimal());
        $this->assertSame('15.00', $a->egresos->decimal());
    }

    public function test_cada_cobro_cuenta_solo_en_su_caja_aunque_sea_el_mismo_usuario_y_dia(): void
    {
        $primera = $this->abrirCaja($this->cajero, '100.00');
        $this->pago($primera, MetodoPago::EFECTIVO, '50.00');
        $primera->update(['estado' => Caja::CERRADA, 'fecha_cierre' => now()]);

        $segunda = $this->abrirCaja($this->cajero, '20.00');
        $this->pago($segunda, MetodoPago::EFECTIVO, '30.00');

        $this->assertSame('150.00', $this->arqueo($primera)->efectivoEsperado->decimal());
        $this->assertSame('50.00', $this->arqueo($segunda)->efectivoEsperado->decimal());
    }

    public function test_centavos_exactos_sin_coma_flotante(): void
    {
        $caja = $this->abrirCaja($this->cajero, '0.10');
        $this->pago($caja, MetodoPago::EFECTIVO, '0.10');
        $this->pago($caja, MetodoPago::EFECTIVO, '0.20');

        $this->assertSame('0.40', $this->arqueo($caja)->efectivoEsperado->decimal());
        $this->assertSame(40, $this->arqueo($caja)->efectivoEsperado->centavos);
    }

    public function test_alquileres_heredados_se_listan_pero_no_entran_al_efectivo(): void
    {
        $caja = $this->abrirCaja($this->cajero, '100.00');
        DB::table('alquileres')->insert([
            'ci_cliente' => $this->cliente()->ci,
            'ci_empleado' => $this->cajero->ci,
            'fecha_inicio' => now(),
            'fecha_fin_estimada' => now()->addDay(),
            'monto_garantia' => '300.00',
            'total_estimado' => '120.00',
            'estado' => 'activo',
            'metodo_pago_id' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $a = $this->arqueo($caja);

        $this->assertSame('100.00', $a->efectivoEsperado->decimal());
        $this->assertCount(1, $a->alquileres);
        $this->assertSame('120.00', $a->alquileres[0]['total_estimado']);
        $this->assertSame('300.00', $a->alquileres[0]['garantia']);
    }

    public function test_la_foto_serializable_conserva_los_importes_como_texto(): void
    {
        $caja = $this->abrirCaja($this->cajero, '100.00');
        $this->pago($caja, MetodoPago::TARJETA, '12.34');

        $foto = $this->arqueo($caja)->toArray();

        $this->assertSame('100.00', $foto['efectivo_esperado']);
        $this->assertSame('12.34', $foto['metodos'][MetodoPago::TARJETA]['recibido']);
        $this->assertArrayHasKey('alquileres_fuera_del_arqueo', $foto);
    }

    public function test_cajas_movimientos_y_eventos_no_se_eliminan_ni_editan(): void
    {
        $caja = $this->abrirCaja($this->cajero);
        $mov = $this->movimiento($caja, MovimientoCaja::INGRESO, '5.00');

        foreach ([fn () => $caja->delete(), fn () => $mov->delete(), fn () => $mov->update(['monto' => '9.00'])] as $i => $accion) {
            try {
                $accion();
                $this->fail("La acción {$i} debía impedirse");
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('5.00', MovimientoCaja::first()->monto);
    }
}
