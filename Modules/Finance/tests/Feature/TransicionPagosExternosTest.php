<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\PagoEvento;
use Modules\Finance\Tests\Support\EscenarioFinanzas;
use Tests\TestCase;

/**
 * Etapa 2 — transición segura de pagos_externos → pagos (registros históricos ficticios).
 */
class TransicionPagosExternosTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioFinanzas;

    private function migracion()
    {
        return require base_path('Modules/Finance/database/migrations/2026_11_22_000006_transferir_pagos_externos_a_pagos.php');
    }

    private function sembrarHistorico(): array
    {
        $cliente = $this->cliente();
        DB::table('pagos_externos')->insert([
            ['proveedor' => 'paypal', 'referencia_externa' => 'ORD-HIST-1', 'referencia_captura' => 'CAP-HIST-1', 'monto' => '150.00', 'moneda' => 'USD', 'estado' => 'aplicado', 'ci_usuario' => $cliente->ci, 'nro_factura' => null, 'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:00:00'],
            ['proveedor' => 'paypal', 'referencia_externa' => 'ORD-HIST-2', 'referencia_captura' => 'CAP-HIST-2', 'monto' => '80.00', 'moneda' => 'USD', 'estado' => 'requiere_reembolso', 'ci_usuario' => $cliente->ci, 'nro_factura' => null, 'created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00'],
        ]);

        return DB::table('pagos_externos')->orderBy('id')->get()->toArray();
    }

    public function test_copia_los_registros_conservando_el_original(): void
    {
        $this->prepararBase();
        $original = $this->sembrarHistorico();

        $this->migracion()->up();

        $this->assertEquals($original, DB::table('pagos_externos')->orderBy('id')->get()->toArray(), 'pagos_externos debe quedar intacta');
        $this->assertSame(2, Pago::whereNotNull('legacy_pago_externo_id')->count());

        $aplicado = Pago::where('referencia_externa', 'ORD-HIST-1')->firstOrFail();
        $this->assertSame('recibido', $aplicado->estado->value);
        $this->assertSame('aplicado', $aplicado->estado_aplicacion->value);
        $this->assertSame('CAP-HIST-1', $aplicado->referencia_captura);

        $revision = Pago::where('referencia_externa', 'ORD-HIST-2')->firstOrFail();
        $this->assertSame('recibido', $revision->estado->value);
        $this->assertSame('requiere_revision', $revision->estado_aplicacion->value);
        $this->assertStringContainsString('requiere_reembolso', $revision->motivo_revision);

        $this->assertSame(2, PagoEvento::where('tipo', 'migrado_desde_pagos_externos')->count());
    }

    public function test_repetir_la_transicion_no_duplica(): void
    {
        $this->prepararBase();
        $this->sembrarHistorico();

        $this->migracion()->up();
        $this->migracion()->up();

        $this->assertSame(2, Pago::count());
    }

    public function test_se_detiene_sin_copiar_si_falta_el_metodo_paypal(): void
    {
        // Base sin sembrar: no existe el método PAYPAL
        $cliente = \Database\Factories\UsuarioFactory::new()->cliente()->create();
        DB::table('pagos_externos')->insert(['proveedor' => 'paypal', 'referencia_externa' => 'ORD-X', 'monto' => '1.00', 'moneda' => 'USD', 'estado' => 'aplicado', 'ci_usuario' => $cliente->ci, 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->migracion()->up();
            $this->fail('Debía detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No se copió nada', $e->getMessage());
        }

        $this->assertSame(0, Pago::count());
        $this->assertSame(1, DB::table('pagos_externos')->count());
    }

    public function test_revertir_se_niega_si_hay_historial_posterior(): void
    {
        $this->prepararBase();
        $this->sembrarHistorico();
        $this->migracion()->up();

        $pago = Pago::firstOrFail();
        PagoEvento::create(['pago_id' => $pago->id, 'tipo' => 'conciliado']);

        $this->expectException(\RuntimeException::class);
        $this->migracion()->down();
    }
}
