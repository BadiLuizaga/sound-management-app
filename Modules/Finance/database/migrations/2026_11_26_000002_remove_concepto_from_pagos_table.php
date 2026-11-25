<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Actualización de alcance de SOUND POS (Etapa 4, antes del paso 4).
 *
 * SOUND POS no gestiona garantías: `pagos.concepto` (alquiler / garantía),
 * creado en el paso 1 solo para separar los depósitos de garantía, se elimina.
 * No modifica ninguna migración anterior.
 *
 * Antes de cambiar nada comprueba que no haya pagos de garantía; después,
 * que el número de pagos y la suma de importes no cambiaron. down() restaura
 * la columna, su índice y su restricción tal como las dejó el paso 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pagos', 'concepto')) {
            throw new RuntimeException('No se modificó nada: pagos ya no tiene la columna concepto.');
        }
        $garantias = DB::table('pagos')->where('concepto', '<>', 'alquiler')->count();
        if ($garantias > 0) {
            throw new RuntimeException("No se modificó nada: hay {$garantias} pago(s) registrados como garantía. Revísalos manualmente antes de migrar.");
        }

        [$filas, $suma] = $this->huella();

        if (DB::getDriverName() === 'mysql') {
            // La FK pagos.reserva_id usa el índice compuesto: se restaura su índice
            // simple original antes de quitar el compuesto (ver 2026_11_25_000008).
            $tieneSimple = collect(Schema::getIndexes('pagos'))->contains(fn ($i) => $i['columns'] === ['reserva_id']);
            if (!$tieneSimple) {
                DB::statement('CREATE INDEX pagos_reserva_id_foreign ON pagos (reserva_id)');
            }
        }
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropIndex('pagos_reserva_concepto_index');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pagos DROP CHECK pagos_concepto_garantia_check');
        }
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('concepto');
        });

        [$filasDespues, $sumaDespues] = $this->huella();
        if ($filas !== $filasDespues || $suma !== $sumaDespues) {
            throw new RuntimeException("Verificación fallida tras quitar pagos.concepto: filas {$filas}→{$filasDespues}, suma {$suma}→{$sumaDespues}.");
        }
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->enum('concepto', ['alquiler', 'garantia'])->default('alquiler')->after('reserva_id');
        });
        Schema::table('pagos', function (Blueprint $table) {
            $table->index(['reserva_id', 'concepto'], 'pagos_reserva_concepto_index');
        });
        if (DB::getDriverName() === 'mysql') {
            // Como en el paso 1: el compuesto sustituye al índice simple de la FK
            if (collect(Schema::getIndexes('pagos'))->pluck('name')->contains('pagos_reserva_id_foreign')) {
                DB::statement('DROP INDEX pagos_reserva_id_foreign ON pagos');
            }
            DB::statement("ALTER TABLE pagos ADD CONSTRAINT pagos_concepto_garantia_check CHECK (concepto <> 'garantia' OR reserva_id IS NOT NULL)");
        }
    }

    /** @return array{int, string} número de pagos y suma de importes en centavos */
    private function huella(): array
    {
        $centavos = 0;
        foreach (DB::table('pagos')->orderBy('id')->pluck('monto') as $monto) {
            [$entero, $decimales] = array_pad(explode('.', (string) $monto, 2), 2, '0');
            $centavos += (int) $entero * 100 + (int) substr(str_pad($decimales, 2, '0'), 0, 2);
        }

        return [DB::table('pagos')->count(), (string) $centavos];
    }
};
