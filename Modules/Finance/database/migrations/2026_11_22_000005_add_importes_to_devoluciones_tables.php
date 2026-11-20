<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 2: importes en las devoluciones (solo columnas nuevas).
 * Permite registrar devoluciones y reembolsos sin modificar la nota de venta
 * original ni sus líneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devoluciones', function (Blueprint $table) {
            $table->decimal('monto_total', 12, 2)->nullable()->after('observaciones');
            $table->integer('ci_aprobado_por')->nullable()->after('monto_total');
            $table->dateTime('aprobado_at')->nullable()->after('ci_aprobado_por');
        });
        Schema::table('devoluciones', function (Blueprint $table) {
            $table->foreign('ci_aprobado_por', 'devoluciones_ci_aprobado_por_foreign')
                ->references('ci')->on('usuario')->restrictOnDelete();
        });

        Schema::table('devolucion_detalles', function (Blueprint $table) {
            $table->decimal('precio_unitario', 12, 2)->nullable()->after('cantidad');
            $table->decimal('subtotal', 12, 2)->nullable()->after('precio_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('devolucion_detalles', function (Blueprint $table) {
            $table->dropColumn(['precio_unitario', 'subtotal']);
        });
        Schema::table('devoluciones', function (Blueprint $table) {
            $table->dropForeign('devoluciones_ci_aprobado_por_foreign');
        });
        Schema::table('devoluciones', function (Blueprint $table) {
            $table->dropColumn(['monto_total', 'ci_aprobado_por', 'aprobado_at']);
        });
    }
};
