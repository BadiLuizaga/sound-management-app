<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 2: tipos de cambio explícitos.
 *
 * `tasa` = BOB por 1 USD. Una fila por fecha de aplicación (vigencia diaria,
 * fecha de La Paz). Sin tasa del día no se ofrece el pago en línea: nunca hay
 * una tasa por defecto. Las tasas usadas por un pago no se modifican.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_cambio', function (Blueprint $table) {
            $table->id();
            $table->char('moneda_base', 3)->default('USD');
            $table->char('moneda_cotizada', 3)->default('BOB');
            $table->decimal('tasa', 12, 6);
            $table->date('fecha_aplicacion');
            $table->string('fuente', 150)->nullable();
            $table->integer('ci_registrado_por');
            $table->timestamps();

            $table->unique(['moneda_base', 'moneda_cotizada', 'fecha_aplicacion'], 'tipos_cambio_dia_unique');
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->restrictOnDelete();
        });

        Schema::create('tipo_cambio_revisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_cambio_id')->constrained('tipos_cambio')->restrictOnDelete();
            $table->decimal('tasa_anterior', 12, 6);
            $table->decimal('tasa_nueva', 12, 6);
            $table->string('motivo', 255);
            $table->integer('ci_usuario');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ci_usuario')->references('ci')->on('usuario')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tipos_cambio ADD CONSTRAINT tipos_cambio_tasa_check CHECK (tasa > 0)');
            DB::statement('ALTER TABLE tipo_cambio_revisiones ADD CONSTRAINT tipo_cambio_revisiones_tasa_check CHECK (tasa_anterior > 0 AND tasa_nueva > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_cambio_revisiones');
        Schema::dropIfExists('tipos_cambio');
    }
};
