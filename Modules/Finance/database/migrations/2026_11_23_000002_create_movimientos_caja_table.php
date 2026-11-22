<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 3: ingresos y egresos manuales de efectivo. SOLO INSERCIÓN.
 *
 *  - caja_id: la caja donde se mueve físicamente el dinero (debe estar abierta).
 *  - revierte_id: un movimiento correctivo inverso referencia al original, que
 *    queda intacto. Un movimiento se revierte una sola vez (único).
 *  - caja_corregida_id: caja cerrada cuyo arqueo corrige este movimiento (D4:
 *    no hay reaperturas; la caja cerrada y su foto no se modifican).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('clave_idempotencia', 80)->unique();
            $table->unsignedBigInteger('caja_id');
            $table->enum('tipo', ['ingreso', 'egreso']);
            $table->decimal('monto', 12, 2);
            $table->char('moneda', 3)->default('BOB');
            $table->string('motivo', 255);
            $table->unsignedBigInteger('revierte_id')->nullable()->unique();
            $table->unsignedBigInteger('caja_corregida_id')->nullable();
            $table->integer('ci_registrado_por');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('caja_id')->references('id')->on('cajas')->restrictOnDelete();
            $table->foreign('revierte_id')->references('id')->on('movimientos_caja')->restrictOnDelete();
            $table->foreign('caja_corregida_id')->references('id')->on('cajas')->restrictOnDelete();
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['caja_id', 'tipo'], 'movimientos_caja_caja_tipo_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_monto_check CHECK (monto > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};
