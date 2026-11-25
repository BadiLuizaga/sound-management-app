<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): unidades fuera de servicio (mantenimiento, daño,
 * faltante u otro) durante un intervalo. La disponibilidad las descuenta SIN
 * margen de preparación. `hasta` nulo = indefinido hasta que se levante.
 *
 * No se modifica `mantenimientos` (sigue ligado a `producto`, D8): el vínculo
 * es opcional y nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('maquinaria_bloqueos')) {
            throw new RuntimeException('No se modificó nada: la tabla maquinaria_bloqueos ya existe.');
        }
        foreach (['maquinarias', 'mantenimientos', 'reserva_incidencias'] as $t) {
            if (!Schema::hasTable($t)) {
                throw new RuntimeException("No se modificó nada: falta la tabla {$t}.");
            }
        }

        Schema::create('maquinaria_bloqueos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maquinaria_id');
            $table->unsignedInteger('cantidad');
            $table->dateTime('desde');
            $table->dateTime('hasta')->nullable();
            $table->enum('motivo', ['mantenimiento', 'dano', 'faltante', 'otro']);
            $table->string('descripcion', 255)->nullable();
            $table->unsignedBigInteger('mantenimiento_id')->nullable();
            $table->unsignedBigInteger('incidencia_id')->nullable();
            $table->enum('estado', ['activo', 'levantado'])->default('activo');
            $table->integer('ci_registrado_por');
            $table->integer('ci_levantado_por')->nullable();
            $table->dateTime('levantado_at')->nullable();
            $table->timestamps();

            $table->foreign('maquinaria_id')->references('id')->on('maquinarias')->restrictOnDelete();
            $table->foreign('mantenimiento_id')->references('id')->on('mantenimientos')->nullOnDelete();
            $table->foreign('incidencia_id')->references('id')->on('reserva_incidencias')->restrictOnDelete();
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_levantado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['maquinaria_id', 'estado', 'desde', 'hasta'], 'maquinaria_bloqueos_disponibilidad_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE maquinaria_bloqueos ADD CONSTRAINT maquinaria_bloqueos_cantidad_check CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE maquinaria_bloqueos ADD CONSTRAINT maquinaria_bloqueos_intervalo_check CHECK (hasta IS NULL OR hasta > desde)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maquinaria_bloqueos');
    }
};
