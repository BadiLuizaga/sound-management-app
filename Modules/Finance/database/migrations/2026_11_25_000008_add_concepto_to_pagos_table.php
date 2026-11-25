<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 4 (paso 1): concepto del pago (C2).
 *
 *  - alquiler (por defecto): ventas y cobros del alquiler. Todos los pagos
 *    existentes quedan como 'alquiler' SIN cambiar ningún importe.
 *  - garantia: depósito de garantía de una reserva. No modifica
 *    `reservas.estado_pago` y se informa aparte en caja, arqueo y reportes.
 *    En MySQL, una garantía exige reserva_id (CHECK).
 *
 * Valida antes de cambiar nada y comprueba después que el número de pagos y la
 * suma de importes no cambiaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pagos')) {
            throw new RuntimeException('No se modificó nada: falta la tabla pagos (Etapa 2).');
        }
        if (Schema::hasColumn('pagos', 'concepto')) {
            throw new RuntimeException('No se modificó nada: pagos ya tiene la columna concepto. Revisa el esquema manualmente antes de migrar.');
        }

        [$filas, $suma] = $this->huella();

        Schema::table('pagos', function (Blueprint $table) {
            $table->enum('concepto', ['alquiler', 'garantia'])->default('alquiler')->after('reserva_id');
        });
        Schema::table('pagos', function (Blueprint $table) {
            $table->index(['reserva_id', 'concepto'], 'pagos_reserva_concepto_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pagos ADD CONSTRAINT pagos_concepto_garantia_check CHECK (concepto <> 'garantia' OR reserva_id IS NOT NULL)");
        }

        [$filasDespues, $sumaDespues] = $this->huella();
        $noAlquiler = DB::table('pagos')->where('concepto', '<>', 'alquiler')->count();
        if ($filas !== $filasDespues || $suma !== $sumaDespues || $noAlquiler !== 0) {
            throw new RuntimeException("Verificación fallida tras añadir pagos.concepto: filas {$filas}→{$filasDespues}, suma {$suma}→{$sumaDespues}, no-alquiler {$noAlquiler}.");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Al crear el índice compuesto (reserva_id, concepto), MySQL descartó el
            // índice automático de la FK pagos.reserva_id (pagos_reserva_id_foreign),
            // y ahora la FK depende del compuesto. Se restaura primero ese índice con
            // su nombre original, para poder quitar el compuesto y dejar el esquema
            // como estaba. SQLite no necesita índices para sus FKs.
            $tieneSimple = collect(Schema::getIndexes('pagos'))
                ->contains(fn ($i) => $i['columns'] === ['reserva_id']);
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
    }

    /** @return array{int, string} número de pagos y suma de importes (en centavos, como texto) */
    private function huella(): array
    {
        $filas = DB::table('pagos')->count();
        $centavos = '0';
        foreach (DB::table('pagos')->orderBy('id')->pluck('monto') as $monto) {
            [$entero, $decimales] = array_pad(explode('.', (string) $monto, 2), 2, '0');
            $centavos = (string) ((int) $centavos + (int) $entero * 100 + (int) substr(str_pad($decimales, 2, '0'), 0, 2));
        }

        return [$filas, $centavos];
    }
};
