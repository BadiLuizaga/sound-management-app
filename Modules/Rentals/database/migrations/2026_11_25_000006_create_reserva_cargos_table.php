<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): cargos adicionales de la reserva (horas extra,
 * daños, faltantes u otros). Su suma vigente alimenta `reservas.total_cargos`.
 * Las horas extra se expresan en horas: el alquiler sigue siendo solo por horas.
 * Un cargo no se borra: se anula (estado 'anulado').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reserva_cargos')) {
            throw new RuntimeException('No se modificó nada: la tabla reserva_cargos ya existe.');
        }
        foreach (['reservas', 'reserva_incidencias'] as $t) {
            if (!Schema::hasTable($t)) {
                throw new RuntimeException("No se modificó nada: falta la tabla {$t}.");
            }
        }

        Schema::create('reserva_cargos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserva_id');
            $table->enum('concepto', ['horas_extra', 'dano', 'faltante', 'otro']);
            $table->string('descripcion', 255)->nullable();
            $table->unsignedInteger('horas')->nullable();
            $table->decimal('monto', 12, 2);
            $table->char('moneda', 3)->default('BOB');
            $table->unsignedBigInteger('incidencia_id')->nullable();
            $table->enum('estado', ['vigente', 'anulado'])->default('vigente');
            $table->string('motivo_anulacion', 255)->nullable();
            $table->integer('ci_registrado_por');
            $table->integer('ci_anulado_por')->nullable();
            $table->dateTime('anulado_at')->nullable();
            $table->string('clave_idempotencia', 80)->unique();
            $table->timestamps();

            $table->foreign('reserva_id')->references('id')->on('reservas')->restrictOnDelete();
            $table->foreign('incidencia_id')->references('id')->on('reserva_incidencias')->restrictOnDelete();
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_anulado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['reserva_id', 'estado'], 'reserva_cargos_reserva_estado_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reserva_cargos ADD CONSTRAINT reserva_cargos_monto_check CHECK (monto > 0)');
            DB::statement("ALTER TABLE reserva_cargos ADD CONSTRAINT reserva_cargos_horas_check CHECK ((horas IS NULL OR horas > 0) AND (concepto <> 'horas_extra' OR horas IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_cargos');
    }
};
