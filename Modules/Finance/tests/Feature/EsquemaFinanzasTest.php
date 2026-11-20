<?php

namespace Modules\Finance\Tests\Feature;

use Database\Factories\UsuarioFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\PagoEvento;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 2 — paso 2: esquema financiero (claves, unicidad, inmutabilidad).
 */
class EsquemaFinanzasTest extends TestCase
{
    use RefreshDatabase;

    private function pagoBase(array $cambios = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'canal' => 'presencial',
            'proveedor' => 'manual',
            'metodo_pago_id' => MetodoPago::idPorCodigo(MetodoPago::TARJETA),
            'estado' => 'recibido',
            'estado_aplicacion' => 'pendiente',
            'monto' => '100.00',
            'moneda' => 'BOB',
            'created_at' => now(),
            'updated_at' => now(),
        ], $cambios);
    }

    public function test_claves_foraneas_de_pagos(): void
    {
        $fks = collect(Schema::getForeignKeys('pagos'))
            ->map(fn ($fk) => implode(',', $fk['columns']) . '>' . strtolower($fk['foreign_table']))
            ->all();

        foreach ([
            'metodo_pago_id>metodopago', 'tipo_cambio_id>tipos_cambio', 'ci_usuario>usuario',
            'caja_id>cajas', 'nro_factura>notaventa', 'reserva_id>reservas', 'legacy_pago_externo_id>pagos_externos',
        ] as $esperada) {
            $this->assertContains($esperada, $fks, "Falta FK {$esperada}");
        }
    }

    public function test_una_referencia_externa_no_se_reutiliza_en_el_mismo_metodo(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('pagos')->insert($this->pagoBase(['referencia_externa' => 'VOUCHER-1']));

        $this->expectException(QueryException::class);
        DB::table('pagos')->insert($this->pagoBase(['referencia_externa' => 'VOUCHER-1']));
    }

    public function test_una_captura_no_se_reutiliza(): void
    {
        $this->seed(DatabaseSeeder::class);
        $paypal = MetodoPago::idPorCodigo(MetodoPago::PAYPAL);
        DB::table('pagos')->insert($this->pagoBase(['proveedor' => 'paypal', 'metodo_pago_id' => $paypal, 'referencia_externa' => 'ORD-1', 'referencia_captura' => 'CAP-1']));

        $this->expectException(QueryException::class);
        DB::table('pagos')->insert($this->pagoBase(['proveedor' => 'paypal', 'metodo_pago_id' => $paypal, 'referencia_externa' => 'ORD-2', 'referencia_captura' => 'CAP-1']));
    }

    public function test_varios_pagos_sin_referencia_estan_permitidos(): void
    {
        $this->seed(DatabaseSeeder::class);
        $efectivo = MetodoPago::idPorCodigo(MetodoPago::EFECTIVO);
        DB::table('pagos')->insert($this->pagoBase(['metodo_pago_id' => $efectivo]));
        DB::table('pagos')->insert($this->pagoBase(['metodo_pago_id' => $efectivo]));

        $this->assertSame(2, DB::table('pagos')->count());
    }

    public function test_pagos_y_eventos_no_se_pueden_eliminar_ni_editar_el_historial(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('pagos')->insert($this->pagoBase());
        $pago = Pago::first();
        $evento = PagoEvento::create(['pago_id' => $pago->id, 'tipo' => 'prueba']);

        try {
            $pago->delete();
            $this->fail('Se permitió eliminar un pago');
        } catch (\LogicException) {
        }

        try {
            $evento->update(['tipo' => 'alterado']);
            $this->fail('Se permitió editar el historial');
        } catch (\LogicException) {
        }

        $this->assertSame(1, Pago::count());
        $this->assertSame('prueba', PagoEvento::first()->tipo);
    }

    public function test_tipo_de_cambio_unico_por_dia(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = UsuarioFactory::new()->conRol('Administrador')->create();
        $fila = ['moneda_base' => 'USD', 'moneda_cotizada' => 'BOB', 'tasa' => '6.960000', 'fecha_aplicacion' => '2026-11-20', 'ci_registrado_por' => $admin->ci, 'created_at' => now(), 'updated_at' => now()];
        DB::table('tipos_cambio')->insert($fila);

        $this->expectException(QueryException::class);
        DB::table('tipos_cambio')->insert(array_merge($fila, ['tasa' => '7.000000']));
    }
}
