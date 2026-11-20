<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 1: renombrada desde 2026_06_21_230100 para ejecutarse después
 * de crear `mantenimientos` (2026_06_22_000002) y de añadir `cantidad` (000006).
 * Idempotente: solo actúa si la tabla todavía tiene la forma antigua.
 * Se separa en pasos para que funcione también en SQLite (pruebas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mantenimientos')
            || !Schema::hasColumn('mantenimientos', 'maquinaria_id')
            || Schema::hasColumn('mantenimientos', 'idproducto')) {
            return;
        }

        Schema::table('mantenimientos', function (Blueprint $table) {
            $table->dropForeign(['maquinaria_id']);
        });

        Schema::table('mantenimientos', function (Blueprint $table) {
            $table->dropColumn('maquinaria_id');
        });

        Schema::table('mantenimientos', function (Blueprint $table) {
            $table->integer('idproducto')->after('id');
            $table->foreign('idproducto')->references('idproducto')->on('producto')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mantenimientos') || !Schema::hasColumn('mantenimientos', 'idproducto')) {
            return;
        }

        Schema::table('mantenimientos', function (Blueprint $table) {
            $table->dropForeign(['idproducto']);
        });

        Schema::table('mantenimientos', function (Blueprint $table) {
            $table->dropColumn('idproducto');
        });

        Schema::table('mantenimientos', function (Blueprint $table) {
            $table->unsignedBigInteger('maquinaria_id')->after('id');
            $table->foreign('maquinaria_id')->references('id')->on('maquinarias')->onDelete('cascade');
        });
    }
};
