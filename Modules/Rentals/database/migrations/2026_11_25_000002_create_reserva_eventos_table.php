<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): historial de la reserva, de SOLO INSERCIÓN
 * (mismo patrón que pago_eventos y caja_eventos). Nunca se borra: FK restrict.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reserva_eventos')) {
            throw new RuntimeException('No se modificó nada: la tabla reserva_eventos ya existe.');
        }

        Schema::create('reserva_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserva_id');
            $table->string('tipo', 50);
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();
            $table->integer('ci_responsable')->nullable();
            $table->json('datos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('reserva_id')->references('id')->on('reservas')->restrictOnDelete();
            $table->foreign('ci_responsable')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['reserva_id', 'created_at'], 'reserva_eventos_reserva_fecha_index');
            $table->index('tipo', 'reserva_eventos_tipo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_eventos');
    }
};
