<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): datos operativos de la reserva (solo columnas nuevas).
 *
 *  - canal: erp | pos | portal. Nulo en las reservas anteriores (no se inventa).
 *  - total_cargos: horas extra, daños y faltantes (reserva_cargos). El importe
 *    a cobrar será total + total_cargos.
 *  - entregada_at / devuelta_at / liquidada_at: hitos del ciclo; "devuelta sin
 *    liquidar" = en_curso con devuelta_at (el enum `estado` no cambia).
 *  - clave_idempotencia: evita reservas duplicadas por doble envío (POS/portal).
 *
 * Antes de cambiar nada valida que el esquema y los datos sean coherentes; si
 * no lo son, se detiene sin modificar la base.
 */
return new class extends Migration
{
    private const COLUMNAS = ['canal', 'total_cargos', 'entregada_at', 'devuelta_at', 'liquidada_at', 'lugar_entrega', 'clave_idempotencia'];

    public function up(): void
    {
        $this->validar();

        Schema::table('reservas', function (Blueprint $table) {
            $table->enum('canal', ['erp', 'pos', 'portal'])->nullable()->after('estado_pago');
            $table->decimal('total_cargos', 12, 2)->default(0)->after('total');
            $table->dateTime('entregada_at')->nullable()->after('cancelada_at');
            $table->dateTime('devuelta_at')->nullable()->after('entregada_at');
            $table->dateTime('liquidada_at')->nullable()->after('devuelta_at');
            $table->string('lugar_entrega', 255)->nullable()->after('liquidada_at');
            $table->string('clave_idempotencia', 80)->nullable()->after('lugar_entrega');
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->unique('clave_idempotencia', 'reservas_clave_idempotencia_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reservas ADD CONSTRAINT reservas_total_cargos_check CHECK (total_cargos >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reservas DROP CHECK reservas_total_cargos_check');
        }
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropUnique('reservas_clave_idempotencia_unique');
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNAS);
        });
    }

    private function validar(): void
    {
        if (!Schema::hasTable('reservas')) {
            throw new RuntimeException('No se modificó nada: falta la tabla reservas (migración 2026_11_20_000003).');
        }
        $existentes = array_values(array_filter(self::COLUMNAS, fn ($c) => Schema::hasColumn('reservas', $c)));
        if ($existentes !== []) {
            throw new RuntimeException('No se modificó nada: reservas ya tiene la(s) columna(s) ' . implode(', ', $existentes) . '. Revisa el esquema manualmente antes de migrar.');
        }

        // SQLite no tiene las CHECK de la Etapa 1: se comprueban aquí.
        $invalidas = DB::table('reservas')
            ->where(fn ($q) => $q->whereColumn('fecha_fin', '<=', 'fecha_inicio')
                ->orWhere('horas', '<=', 0)
                ->orWhere('total', '<', 0))
            ->count();
        if ($invalidas > 0) {
            throw new RuntimeException("No se modificó nada: hay {$invalidas} reserva(s) con intervalo, horas o total inválidos. Revísalas manualmente antes de migrar.");
        }
    }
};
