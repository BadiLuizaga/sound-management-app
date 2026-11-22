<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 3: foto del arqueo en `cajas` (solo columnas nuevas).
 *
 *  - version_arqueo: 1 = arqueo heredado (por empleado y fecha, sin métodos);
 *    2 = arqueo por caja_id y método. Por defecto 1; el servicio nuevo fija 2.
 *  - abierta_de: columna generada (user_id si la caja está abierta, NULL si no)
 *    con índice único: refuerza en la base "una sola caja abierta por usuario".
 *
 * No modifica importes existentes. Si hubiera datos que impidan las
 * restricciones, se detiene sin cambiar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $negativos = DB::table('cajas')->where('monto_apertura', '<', 0)->count();
        $duplicadas = DB::table('cajas')->where('estado', 'abierta')
            ->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->get()->count();
        if ($negativos > 0 || $duplicadas > 0) {
            throw new RuntimeException(
                "No se modificó nada: hay {$negativos} caja(s) con apertura negativa y {$duplicadas} usuario(s) con más de una caja abierta. Revísalas manualmente antes de migrar."
            );
        }

        Schema::table('cajas', function (Blueprint $table) {
            $table->unsignedTinyInteger('version_arqueo')->default(1)->after('fecha_cierre');
            $table->decimal('efectivo_esperado', 12, 2)->nullable()->after('version_arqueo');
            $table->decimal('efectivo_contado', 12, 2)->nullable()->after('efectivo_esperado');
            $table->json('resumen')->nullable()->after('efectivo_contado');
            $table->integer('ci_cerrado_por')->nullable()->after('resumen');
            $table->boolean('cierre_supervisado')->default(false)->after('ci_cerrado_por');
            $table->string('observaciones', 500)->nullable()->after('cierre_supervisado');
            $table->string('clave_cierre', 80)->nullable()->after('observaciones');
        });

        Schema::table('cajas', function (Blueprint $table) {
            $table->foreign('ci_cerrado_por', 'cajas_ci_cerrado_por_foreign')
                ->references('ci')->on('usuario')->restrictOnDelete();
            $table->unique('clave_cierre', 'cajas_clave_cierre_unique');
        });

        $expresion = "CASE WHEN estado = 'abierta' THEN user_id ELSE NULL END";
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cajas ADD COLUMN abierta_de INT GENERATED ALWAYS AS ({$expresion}) VIRTUAL");
            DB::statement('ALTER TABLE cajas ADD CONSTRAINT cajas_monto_apertura_check CHECK (monto_apertura >= 0)');
        } else {
            DB::statement("ALTER TABLE cajas ADD COLUMN abierta_de INTEGER GENERATED ALWAYS AS ({$expresion}) VIRTUAL");
        }
        DB::statement('CREATE UNIQUE INDEX cajas_abierta_de_unique ON cajas (abierta_de)');
    }

    public function down(): void
    {
        DB::statement(DB::getDriverName() === 'mysql'
            ? 'DROP INDEX cajas_abierta_de_unique ON cajas'
            : 'DROP INDEX cajas_abierta_de_unique');
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE cajas DROP CHECK cajas_monto_apertura_check');
        }
        DB::statement('ALTER TABLE cajas DROP COLUMN abierta_de');

        // Forma por columnas: en SQLite Laravel reconstruye la tabla; en MySQL
        // equivale al nombre cajas_ci_cerrado_por_foreign.
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropForeign(['ci_cerrado_por']);
        });
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropUnique('cajas_clave_cierre_unique');
        });
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropColumn([
                'version_arqueo', 'efectivo_esperado', 'efectivo_contado', 'resumen',
                'ci_cerrado_por', 'cierre_supervisado', 'observaciones', 'clave_cierre',
            ]);
        });
    }
};
