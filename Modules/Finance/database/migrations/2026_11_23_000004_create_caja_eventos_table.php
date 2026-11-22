<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 3: historial de una caja. SOLO INSERCIÓN.
 * Registra el titular de la caja y el responsable de cada acción (en un cierre
 * supervisado son personas distintas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caja_id');
            $table->string('tipo', 50);
            $table->integer('ci_titular');
            $table->integer('ci_responsable')->nullable();
            $table->json('datos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('caja_id')->references('id')->on('cajas')->restrictOnDelete();
            $table->foreign('ci_titular')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_responsable')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['caja_id', 'created_at'], 'caja_eventos_caja_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_eventos');
    }
};
