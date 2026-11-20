<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 2: reembolsos (PayPal y presenciales), totales o parciales.
 * Idempotentes, con doble paso (solicitar → autorizar/ejecutar) y la tasa del
 * pago original. Nunca se eliminan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reembolsos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('clave_idempotencia', 80)->unique();
            $table->foreignId('pago_id')->constrained('pagos')->restrictOnDelete();
            $table->unsignedBigInteger('devolucion_id')->nullable();
            $table->enum('estado', ['solicitado', 'autorizado', 'procesando', 'completado', 'fallido', 'incierto', 'rechazado'])
                ->default('solicitado');
            $table->enum('origen', ['aplicacion', 'externo_webhook'])->default('aplicacion');
            $table->decimal('monto', 12, 2);
            $table->char('moneda', 3)->default('BOB');
            $table->decimal('monto_proveedor', 12, 2)->nullable();
            $table->char('moneda_proveedor', 3)->nullable();
            $table->decimal('tasa_aplicada', 12, 6)->nullable();
            $table->string('referencia_externa', 100)->nullable()->unique();
            $table->string('motivo', 255);
            $table->integer('ci_solicitado_por')->nullable();
            $table->integer('ci_autorizado_por')->nullable();
            $table->json('respuesta_proveedor')->nullable();
            $table->dateTime('solicitado_at')->nullable();
            $table->dateTime('autorizado_at')->nullable();
            $table->dateTime('completado_at')->nullable();
            $table->timestamps();

            $table->foreign('devolucion_id')->references('id')->on('devoluciones')->restrictOnDelete();
            $table->foreign('ci_solicitado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_autorizado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index('estado', 'reembolsos_estado_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reembolsos ADD CONSTRAINT reembolsos_monto_check CHECK (monto > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reembolsos');
    }
};
