<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): garantías de la reserva, SEPARADAS del alquiler (C2).
 *
 *  - tipo dinero: el depósito es un pago con concepto 'garantia' (pago_id); su
 *    devolución es un reembolso de ese pago (reembolso_id). No cambia
 *    `reservas.estado_pago`.
 *  - tipo documento: sin pago (p. ej., CI original); monto informativo.
 *  - monto_retenido: parte retenida por una incidencia (incidencia_id).
 *
 * Un pago respalda como máximo una garantía (pago_id único).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reserva_garantias')) {
            throw new RuntimeException('No se modificó nada: la tabla reserva_garantias ya existe.');
        }
        foreach (['reservas', 'pagos', 'reembolsos', 'reserva_incidencias'] as $t) {
            if (!Schema::hasTable($t)) {
                throw new RuntimeException("No se modificó nada: falta la tabla {$t}.");
            }
        }

        Schema::create('reserva_garantias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserva_id');
            $table->enum('tipo', ['dinero', 'documento']);
            $table->decimal('monto', 12, 2)->default(0);
            $table->char('moneda', 3)->default('BOB');
            $table->string('descripcion', 255)->nullable();
            $table->enum('estado', ['pendiente', 'recibida', 'devuelta', 'devuelta_parcial', 'retenida'])->default('pendiente');
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->unsignedBigInteger('reembolso_id')->nullable();
            $table->decimal('monto_retenido', 12, 2)->default(0);
            $table->unsignedBigInteger('incidencia_id')->nullable();
            $table->integer('ci_registrado_por');
            $table->integer('ci_resuelto_por')->nullable();
            $table->dateTime('recibida_at')->nullable();
            $table->dateTime('resuelta_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('clave_idempotencia', 80)->unique();
            $table->timestamps();

            $table->foreign('reserva_id')->references('id')->on('reservas')->restrictOnDelete();
            $table->foreign('pago_id')->references('id')->on('pagos')->restrictOnDelete();
            $table->foreign('reembolso_id')->references('id')->on('reembolsos')->restrictOnDelete();
            $table->foreign('incidencia_id')->references('id')->on('reserva_incidencias')->restrictOnDelete();
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_resuelto_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->unique('pago_id', 'reserva_garantias_pago_unique');
            $table->unique('reembolso_id', 'reserva_garantias_reembolso_unique');
            $table->index(['reserva_id', 'estado'], 'reserva_garantias_reserva_estado_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reserva_garantias ADD CONSTRAINT reserva_garantias_monto_check CHECK (monto >= 0 AND monto_retenido >= 0 AND monto_retenido <= monto)');
            DB::statement("ALTER TABLE reserva_garantias ADD CONSTRAINT reserva_garantias_dinero_check CHECK (tipo <> 'dinero' OR monto > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_garantias');
    }
};
