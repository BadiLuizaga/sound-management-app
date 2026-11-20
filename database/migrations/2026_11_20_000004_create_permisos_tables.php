<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 1: permisos del sistema almacenados en la base.
 *
 * `permisos` es el catálogo de habilidades (mismos códigos que los Gates de
 * config/access.php). `rol_permiso` asigna permisos a cada rol de `rol`,
 * incluidos los roles personalizados creados desde "Trabajos", que así
 * tendrán permisos explícitos en lugar de perderlos sin aviso.
 *
 * En esta etapa solo se crea y se siembra la estructura; la lectura de estos
 * permisos por AccessControl se implementa en la Etapa 3 (permisos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 100)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->integer('id_rol');
            $table->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['id_rol', 'permiso_id']);
            $table->foreign('id_rol')->references('id')->on('rol')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rol_permiso');
        Schema::dropIfExists('permisos');
    }
};
