<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 3: de qué caja y por qué medio se entregó un reembolso
 * (solo columnas nuevas, nulas). Los reembolsos anteriores quedan "sin caja":
 * no se inventa su asignación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reembolsos', function (Blueprint $table) {
            $table->unsignedBigInteger('caja_id')->nullable()->after('devolucion_id');
            $table->enum('medio_devolucion', ['efectivo', 'tarjeta', 'transferencia', 'qr', 'paypal'])->nullable()->after('caja_id');
            $table->integer('ci_entregado_por')->nullable()->after('ci_autorizado_por');
            $table->dateTime('entregado_at')->nullable()->after('completado_at');
        });
        Schema::table('reembolsos', function (Blueprint $table) {
            $table->foreign('caja_id', 'reembolsos_caja_id_foreign')->references('id')->on('cajas')->restrictOnDelete();
            $table->foreign('ci_entregado_por', 'reembolsos_ci_entregado_por_foreign')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['caja_id', 'medio_devolucion'], 'reembolsos_caja_medio_index');
        });
    }

    public function down(): void
    {
        Schema::table('reembolsos', function (Blueprint $table) {
            $table->dropForeign(['caja_id']);
            $table->dropForeign(['ci_entregado_por']);
        });
        Schema::table('reembolsos', function (Blueprint $table) {
            $table->dropIndex('reembolsos_caja_medio_index');
        });
        Schema::table('reembolsos', function (Blueprint $table) {
            $table->dropColumn(['caja_id', 'medio_devolucion', 'ci_entregado_por', 'entregado_at']);
        });
    }
};
