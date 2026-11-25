<?php

namespace Modules\Procurement\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\Reembolso;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Modules\Inventory\Models\Producto;
use Modules\Procurement\Models\Devolucion;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Devoluciones de productos (ventas de mostrador). Alcance de SOUND POS: no se
 * devuelve dinero. Aprobar una "Devolución" o una "Garantía" (reclamo por
 * falla del producto) solo repone el stock; el importe queda como referencia.
 * La nota de venta y sus pagos no se modifican.
 */
class DevolucionProductosTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
    }

    private function ventaEnCajaConDescuento(): int
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $this->abrirCaja($vendedor);
        $this->producto(20, '50.00', 10);
        $r = $this->actingAs($vendedor)->postJson('/ventas', [
            'ci_cliente' => $this->cliente()->ci,
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::TARJETA),
            'productos' => [['id' => 20, 'cantidad' => 2, 'descuento' => 10]], // neto 90.00
            'referencia' => 'VOUCHER-DEV-1',
            'clave_idempotencia' => 'pos-devolucion-00000001',
        ])->assertOk();

        return (int) $r->json('nro_factura');
    }

    private function registrarDevolucion(int $nro, int $cantidad, string $tipo = 'Devolución'): Devolucion
    {
        $this->actingAs($this->admin)->postJson('/devoluciones', [
            'nro_factura' => $nro,
            'tipo' => $tipo,
            'motivo' => 'Prueba de devolución',
            'productos' => [['id' => 20, 'cantidad' => $cantidad]],
        ])->assertOk();

        return Devolucion::latest('id')->firstOrFail();
    }

    public function test_aprobar_repone_el_stock_sin_devolver_dinero_ni_tocar_la_nota(): void
    {
        $nro = $this->ventaEnCajaConDescuento();
        $notaAntes = DB::table('NotaVenta')->where('nro', $nro)->first();
        $lineasAntes = DB::table('detalleNotaVenta')->where('nro_factura', $nro)->get()->toArray();
        $pagoAntes = Pago::where('nro_factura', $nro)->firstOrFail()->only(['estado', 'monto']);

        $dev = $this->registrarDevolucion($nro, 1);
        $this->actingAs($this->admin)->post("/devoluciones/{$dev->id}/aprobar")
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'No se registra ninguna devolución de dinero'));

        $this->assertEquals($notaAntes, DB::table('NotaVenta')->where('nro', $nro)->first());
        $this->assertEquals($lineasAntes, DB::table('detalleNotaVenta')->where('nro_factura', $nro)->get()->toArray());

        $dev->refresh();
        $this->assertSame('Aprobado', $dev->estado);
        $this->assertSame('45.00', (string) $dev->monto_total, 'Importe solo como referencia: neto 90 / 2 unidades');
        $this->assertSame('45.00', (string) $dev->detalles->first()->subtotal);
        $this->assertSame(9, Producto::find(20)->cantidad); // 10 − 2 + 1

        $this->assertSame(0, Reembolso::count(), 'No se crea ningún reembolso');
        $this->assertSame($pagoAntes, Pago::where('nro_factura', $nro)->firstOrFail()->only(['estado', 'monto']));
    }

    public function test_no_se_puede_devolver_mas_de_lo_vendido(): void
    {
        $nro = $this->ventaEnCajaConDescuento();

        $d1 = $this->registrarDevolucion($nro, 1);
        $this->actingAs($this->admin)->post("/devoluciones/{$d1->id}/aprobar")->assertSessionHas('success');

        $d2 = $this->registrarDevolucion($nro, 2); // 1 + 2 > 2 vendidas
        $this->actingAs($this->admin)->post("/devoluciones/{$d2->id}/aprobar")->assertSessionHas('error');

        $this->assertSame('Pendiente', $d2->fresh()->estado);
        $this->assertSame(9, Producto::find(20)->cantidad);
    }

    public function test_garantia_del_producto_repone_stock_con_importe_cero(): void
    {
        $nro = $this->ventaEnCajaConDescuento();
        $dev = $this->registrarDevolucion($nro, 1, 'Garantía');

        $this->actingAs($this->admin)->post("/devoluciones/{$dev->id}/aprobar")->assertSessionHas('success');

        $this->assertSame(0, Reembolso::count());
        $this->assertSame('0.00', (string) $dev->fresh()->monto_total);
        $this->assertSame(9, Producto::find(20)->cantidad);
        $this->assertSame(1, FacturaVenta::count());
    }
}
