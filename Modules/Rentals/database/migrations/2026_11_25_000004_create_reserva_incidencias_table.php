<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): incidencias de una reserva (daño, faltante,
 * retraso u otro). Se crea antes que garantías, cargos y bloqueos porque
 * estas tablas la referencian.
 *
 * `mantenimiento_id` es opcional (D8): `mantenimientos` sigue ligado a
 * `producto`. Se usa nullOnDelete porque un mantenimiento puede desaparecer
 * en cascada al borrar su producto; la incidencia no debe impedirlo ni perderse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reserva_incidencias')) {
            throw new RuntimeException('No se modificó nada: la tabla reserva_incidencias ya existe.');
        }
        foreach (['reservas', 'reserva_entregas', 'maquinarias', 'mantenimientos'] as $t) {
            if (!Schema::hasTable($t)) {
                throw new RuntimeException("No se modificó nada: falta la tabla {$t}.");
            }
        }

        Schema::create('reserva_incidencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserva_id');
            $table->unsignedBigInteger('reserva_entrega_id')->nullable();
            $table->unsignedBigInteger('maquinaria_id')->nullable();
            $table->enum('tipo', ['dano', 'faltante', 'retraso', 'otro']);
            $table->unsignedInteger('cantidad')->default(1);
            $table->text('descripcion');
            $table->decimal('costo_estimado', 12, 2)->default(0);
            $table->char('moneda', 3)->default('BOB');
            $table->enum('estado', ['abierta', 'resuelta', 'anulada'])->default('abierta');
            $table->text('resolucion')->nullable();
            $table->unsignedBigInteger('mantenimiento_id')->nullable();
            $table->integer('ci_registrado_por');
            $table->integer('ci_resuelto_por')->nullable();
            $table->dateTime('resuelta_at')->nullable();
            $table->timestamps();

            $table->foreign('reserva_id')->references('id')->on('reservas')->restrictOnDelete();
            $table->foreign('reserva_entrega_id')->references('id')->on('reserva_entregas')->restrictOnDelete();
            $table->foreign('maquinaria_id')->references('id')->on('maquinarias')->restrictOnDelete();
            $table->foreign('mantenimiento_id')->references('id')->on('mantenimientos')->nullOnDelete();
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_resuelto_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['reserva_id', 'estado'], 'reserva_incidencias_reserva_estado_index');
            $table->index('estado', 'reserva_incidencias_estado_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reserva_incidencias ADD CONSTRAINT reserva_incidencias_cantidad_check CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE reserva_incidencias ADD CONSTRAINT reserva_incidencias_costo_check CHECK (costo_estimado >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_incidencias');
    }
};
