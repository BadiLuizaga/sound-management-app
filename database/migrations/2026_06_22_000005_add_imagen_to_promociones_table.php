<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 1: renombrada desde 2026_06_21_221019 para ejecutarse después
 * de crear `promociones` (2026_06_22_000004). Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promociones') && !Schema::hasColumn('promociones', 'imagen')) {
            Schema::table('promociones', function (Blueprint $table) {
                $table->string('imagen', 2048)->nullable()->after('descripcion');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('promociones') && Schema::hasColumn('promociones', 'imagen')) {
            Schema::table('promociones', function (Blueprint $table) {
                $table->dropColumn('imagen');
            });
        }
    }
};
