<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 2: eventos de webhook recibidos (idempotencia por evento_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 30);
            $table->string('evento_id', 100);
            $table->string('tipo', 100);
            $table->boolean('firma_valida')->default(false);
            $table->json('payload');
            $table->json('cabeceras')->nullable();
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->dateTime('procesado_at')->nullable();
            $table->unsignedInteger('intentos')->default(0);
            $table->string('resultado', 30)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['proveedor', 'evento_id'], 'webhook_eventos_proveedor_evento_unique');
            $table->foreign('pago_id')->references('id')->on('pagos')->restrictOnDelete();
            $table->index('procesado_at', 'webhook_eventos_procesado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_eventos');
    }
};
