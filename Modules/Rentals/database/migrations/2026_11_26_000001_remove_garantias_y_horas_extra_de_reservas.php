<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Actualización de alcance de SOUND POS (Etapa 4, antes del paso 4).
 *
 * Elimina de las reservas lo que ya no forma parte del producto:
 *  - Garantías: se borra la tabla `reserva_garantias` (creada en el paso 1).
 *  - Horas adicionales: `reserva_cargos` pierde el concepto 'horas_extra' y la
 *    columna `horas`; los cargos por daño, faltante u otro se conservan.
 *  - Retrasos: `reserva_incidencias` pierde el tipo 'retraso'.
 *
 * No modifica ninguna migración anterior. Antes de cambiar nada comprueba que
 * no haya datos que se perderían; si los hay, se detiene sin cambios.
 * down() restaura exactamente el esquema del paso 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['reserva_garantias', 'reserva_cargos', 'reserva_incidencias'] as $t) {
            if (!Schema::hasTable($t)) {
                throw new RuntimeException("No se modificó nada: falta la tabla {$t}.");
            }
        }
        if (!Schema::hasColumn('reserva_cargos', 'horas')) {
            throw new RuntimeException('No se modificó nada: reserva_cargos ya no tiene la columna horas.');
        }

        $garantias = DB::table('reserva_garantias')->count();
        $horasExtra = DB::table('reserva_cargos')->where(fn ($q) => $q->where('concepto', 'horas_extra')->orWhereNotNull('horas'))->count();
        $retrasos = DB::table('reserva_incidencias')->where('tipo', 'retraso')->count();
        if ($garantias + $horasExtra + $retrasos > 0) {
            throw new RuntimeException(
                "No se modificó nada: hay {$garantias} garantía(s), {$horasExtra} cargo(s) por horas extra y {$retrasos} incidencia(s) por retraso. Revísalos manualmente antes de migrar."
            );
        }

        Schema::dropIfExists('reserva_garantias');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reserva_cargos DROP CHECK reserva_cargos_horas_check');
        }
        Schema::table('reserva_cargos', function (Blueprint $table) {
            $table->dropColumn('horas');
        });
        Schema::table('reserva_cargos', function (Blueprint $table) {
            $table->enum('concepto', ['dano', 'faltante', 'otro'])->change();
        });

        Schema::table('reserva_incidencias', function (Blueprint $table) {
            $table->enum('tipo', ['dano', 'faltante', 'otro'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('reserva_incidencias', function (Blueprint $table) {
            $table->enum('tipo', ['dano', 'faltante', 'retraso', 'otro'])->change();
        });

        Schema::table('reserva_cargos', function (Blueprint $table) {
            $table->enum('concepto', ['horas_extra', 'dano', 'faltante', 'otro'])->change();
        });
        Schema::table('reserva_cargos', function (Blueprint $table) {
            $table->unsignedInteger('horas')->nullable()->after('descripcion');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reserva_cargos ADD CONSTRAINT reserva_cargos_horas_check CHECK ((horas IS NULL OR horas > 0) AND (concepto <> 'horas_extra' OR horas IS NOT NULL))");
        }

        // Igual que 2026_11_25_000005_create_reserva_garantias_table
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
};
