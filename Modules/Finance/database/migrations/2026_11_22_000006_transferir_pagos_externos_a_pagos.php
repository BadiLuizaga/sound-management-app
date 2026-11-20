<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fase 2 — Etapa 2: transición segura de `pagos_externos` a `pagos`.
 *
 *  - NO elimina `pagos_externos`, ni sus filas, ni su migración: queda como
 *    histórico de solo lectura (el código deja de escribir en ella).
 *  - COPIA cada fila a `pagos`, idempotente por `legacy_pago_externo_id` (UNIQUE).
 *  - Si hay filas que copiar y no existe el método PAYPAL, se detiene sin
 *    copiar nada.
 *  - down() solo elimina las copias si no tienen historial posterior.
 */
return new class extends Migration
{
    private const MAPEO = [
        'aplicado' => ['recibido', 'aplicado'],
        'requiere_reembolso' => ['recibido', 'requiere_revision'],
    ];

    public function up(): void
    {
        $pendientes = DB::table('pagos_externos')
            ->whereNotIn('id', DB::table('pagos')->whereNotNull('legacy_pago_externo_id')->select('legacy_pago_externo_id'))
            ->orderBy('id')
            ->get();

        if ($pendientes->isEmpty()) {
            return;
        }

        $metodoPaypal = DB::table('metodoPago')->where('codigo', 'PAYPAL')->value('id');
        if ($metodoPaypal === null) {
            throw new RuntimeException(
                'Transición de pagos_externos detenida: no existe el método de pago PAYPAL. ' .
                'Ejecuta `php artisan db:seed --class=MetodoPagoSeeder` y vuelve a migrar. No se copió nada.'
            );
        }

        DB::transaction(function () use ($pendientes, $metodoPaypal) {
            foreach ($pendientes as $fila) {
                [$estado, $aplicacion] = self::MAPEO[$fila->estado] ?? ['recibido', 'requiere_revision'];
                $ahora = now();

                $pagoId = DB::table('pagos')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'canal' => 'en_linea',
                    'proveedor' => $fila->proveedor ?: 'paypal',
                    'metodo_pago_id' => $metodoPaypal,
                    'estado' => $estado,
                    'estado_aplicacion' => $aplicacion,
                    'monto' => $fila->monto,
                    'moneda' => 'BOB',
                    // Moneda y monto realmente cobrados por el proveedor (tal como se registraron)
                    'monto_proveedor' => $fila->monto,
                    'moneda_proveedor' => $fila->moneda,
                    'referencia_externa' => $fila->referencia_externa,
                    'referencia_captura' => $fila->referencia_captura,
                    'ci_usuario' => $fila->ci_usuario,
                    'nro_factura' => $fila->nro_factura,
                    'legacy_pago_externo_id' => $fila->id,
                    'motivo_revision' => $aplicacion === 'requiere_revision'
                        ? "Migrado desde pagos_externos (estado original: {$fila->estado})."
                        : null,
                    'recibido_at' => $fila->created_at,
                    'aplicado_at' => $aplicacion === 'aplicado' ? $fila->updated_at : null,
                    'created_at' => $fila->created_at ?? $ahora,
                    'updated_at' => $ahora,
                ]);

                DB::table('pago_eventos')->insert([
                    'pago_id' => $pagoId,
                    'tipo' => 'migrado_desde_pagos_externos',
                    'estado_nuevo' => $estado,
                    'estado_aplicacion_nuevo' => $aplicacion,
                    'datos' => json_encode(['pago_externo_id' => $fila->id, 'estado_original' => $fila->estado]),
                    'created_at' => $ahora,
                ]);
            }
        });
    }

    public function down(): void
    {
        $copias = DB::table('pagos')->whereNotNull('legacy_pago_externo_id')->pluck('id');
        if ($copias->isEmpty()) {
            return;
        }

        $conHistorial = DB::table('reembolsos')->whereIn('pago_id', $copias)->exists()
            || DB::table('pago_eventos')->whereIn('pago_id', $copias)->where('tipo', '!=', 'migrado_desde_pagos_externos')->exists()
            || DB::table('webhook_eventos')->whereIn('pago_id', $copias)->exists();

        if ($conHistorial) {
            throw new RuntimeException(
                'No se revierte la transición: los pagos migrados desde pagos_externos ya tienen historial posterior. ' .
                'pagos_externos sigue intacta.'
            );
        }

        DB::table('pago_eventos')->whereIn('pago_id', $copias)->delete();
        DB::table('pagos')->whereIn('id', $copias)->delete();
    }
};
