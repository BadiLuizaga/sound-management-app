<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): entregas y devoluciones de equipos.
 *
 *  - reserva_entregas: cabecera (entrega o devolución), responsable y hora real.
 *  - reserva_entrega_detalles: unidades por equipo y su estado
 *    (bueno / danado / faltante) — el checklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['reserva_entregas', 'reserva_entrega_detalles'] as $t) {
            if (Schema::hasTable($t)) {
                throw new RuntimeException("No se modificó nada: la tabla {$t} ya existe.");
            }
        }

        Schema::create('reserva_entregas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserva_id');
            $table->enum('tipo', ['entrega', 'devolucion']);
            $table->dateTime('fecha');
            $table->integer('ci_responsable');
            $table->text('observaciones')->nullable();
            $table->string('clave_idempotencia', 80)->unique();
            $table->timestamps();

            $table->foreign('reserva_id')->references('id')->on('reservas')->restrictOnDelete();
            $table->foreign('ci_responsable')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['reserva_id', 'tipo'], 'reserva_entregas_reserva_tipo_index');
        });

        Schema::create('reserva_entrega_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserva_entrega_id');
            $table->unsignedBigInteger('maquinaria_id');
            $table->unsignedInteger('cantidad');
            $table->enum('estado_equipo', ['bueno', 'danado', 'faltante'])->default('bueno');
            $table->string('observaciones', 500)->nullable();
            $table->timestamps();

            $table->foreign('reserva_entrega_id')->references('id')->on('reserva_entregas')->cascadeOnDelete();
            $table->foreign('maquinaria_id')->references('id')->on('maquinarias')->restrictOnDelete();
            $table->unique(['reserva_entrega_id', 'maquinaria_id', 'estado_equipo'], 'reserva_entrega_detalles_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reserva_entrega_detalles ADD CONSTRAINT reserva_entrega_detalles_cantidad_check CHECK (cantidad > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_entrega_detalles');
        Schema::dropIfExists('reserva_entregas');
    }
};
