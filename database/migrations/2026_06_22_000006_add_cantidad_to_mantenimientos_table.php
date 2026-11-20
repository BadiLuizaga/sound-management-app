<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 1: renombrada desde 2026_06_21_225458 para ejecutarse después
 * de crear `mantenimientos` (2026_06_22_000002). Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mantenimientos') && !Schema::hasColumn('mantenimientos', 'cantidad')) {
            Schema::table('mantenimientos', function (Blueprint $table) {
                $table->integer('cantidad')->default(1)->after('maquinaria_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mantenimientos') && Schema::hasColumn('mantenimientos', 'cantidad')) {
            Schema::table('mantenimientos', function (Blueprint $table) {
                $table->dropColumn('cantidad');
            });
        }
    }
};
