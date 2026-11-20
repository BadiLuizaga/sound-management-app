<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — seguridad: registro idempotente de pagos verificados en el servidor.
 * Sin claves foráneas a propósito: NotaVenta todavía no tiene migración propia
 * (ver FASE_1_ESTABILIZACION.md §5) y esta tabla no debe bloquear su corrección.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_externos', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 30);
            $table->string('referencia_externa', 100);
            $table->string('referencia_captura', 100)->nullable();
            $table->decimal('monto', 12, 2);
            $table->char('moneda', 3);
            $table->string('estado', 20);
            $table->integer('ci_usuario');
            $table->integer('nro_factura')->nullable();
            $table->timestamps();

            $table->unique(['proveedor', 'referencia_externa']);
            $table->index('ci_usuario');
            $table->index('nro_factura');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_externos');
    }
};
