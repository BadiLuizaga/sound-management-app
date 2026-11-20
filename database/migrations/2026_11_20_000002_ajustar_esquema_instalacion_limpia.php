<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 1: ajustes de esquema para una instalación limpia.
 *
 *  - Longitudes de columna coherentes con las validaciones de los
 *    controladores (en modo strict, un valor válido más largo que la columna
 *    provocaba un error 500).
 *  - Unicidad e integridad en la base (antes solo se validaba en PHP).
 *  - `metodoPago.codigo` / `activo`: los métodos se identifican por código,
 *    nunca por un ID fijo.
 *  - Índices para las consultas reales (caja, alquileres, bitácora).
 *
 * Pensada para una base nueva: no convierte datos existentes. Cada bloque va
 * en su propio Schema::table para que funcione también en SQLite (pruebas).
 */
return new class extends Migration
{
    public function up(): void
    {
        // usuario: el registro valida email hasta 255, el perfil nombre/apellido
        // hasta 100, domicilio hasta 255 y teléfono como texto de hasta 20.
        Schema::table('usuario', function (Blueprint $table) {
            $table->string('nombre', 100)->change();
            $table->string('apellido', 100)->change();
            $table->string('email', 255)->change();
            $table->string('domicilio', 255)->nullable()->change();
            $table->string('telefono', 20)->nullable()->change();
        });
        Schema::table('usuario', function (Blueprint $table) {
            $table->unique('email', 'usuario_email_unique');
        });

        // producto: nombre hasta 255 y URL de imagen hasta 2048 (validaciones existentes)
        Schema::table('producto', function (Blueprint $table) {
            $table->string('nombre', 255)->change();
            $table->string('imagen', 2048)->nullable()->change();
        });

        // rol: los permisos se resuelven por nombre → único
        Schema::table('rol', function (Blueprint $table) {
            $table->string('nombre', 100)->change();
            $table->text('descripcion')->nullable()->change();
        });
        Schema::table('rol', function (Blueprint $table) {
            $table->unique('nombre', 'rol_nombre_unique');
        });

        // categoria: el formulario permite descripción vacía
        Schema::table('categoria', function (Blueprint $table) {
            $table->text('descripcion')->nullable()->change();
        });

        // marca: el controlador ya exige nombre único
        Schema::table('marca', function (Blueprint $table) {
            $table->unique('nombre', 'marca_nombre_unique');
        });

        // metodoPago: identificación por código
        Schema::table('metodoPago', function (Blueprint $table) {
            $table->string('codigo', 30)->nullable()->after('id');
            $table->boolean('activo')->default(true)->after('nombre');
        });
        Schema::table('metodoPago', function (Blueprint $table) {
            $table->unique('codigo', 'metodopago_codigo_unique');
        });

        // cajas: integridad con usuario e índice para "caja abierta del usuario"
        Schema::table('cajas', function (Blueprint $table) {
            $table->foreign('user_id', 'cajas_user_id_foreign')
                ->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['user_id', 'estado'], 'cajas_user_id_estado_index');
        });

        // carritos: una línea por usuario y producto
        Schema::table('carritos', function (Blueprint $table) {
            $table->unique(['ci_usuario', 'idproducto'], 'carritos_usuario_producto_unique');
        });
        // Nota: tras esto MySQL usa este índice único para la FK de `ci_usuario`;
        // por eso down() crea un índice simple antes de eliminarlo.

        // Índices de consulta
        Schema::table('alquileres', function (Blueprint $table) {
            $table->index(['estado', 'fecha_inicio'], 'alquileres_estado_fecha_index');
        });
        Schema::table('maquinarias', function (Blueprint $table) {
            $table->index('estado', 'maquinarias_estado_index');
        });
        Schema::table('bitacoras', function (Blueprint $table) {
            $table->index('created_at', 'bitacoras_created_at_index');
            $table->index(['tabla', 'registro_id'], 'bitacoras_tabla_registro_index');
        });
    }

    public function down(): void
    {
        Schema::table('bitacoras', function (Blueprint $table) {
            $table->dropIndex('bitacoras_tabla_registro_index');
            $table->dropIndex('bitacoras_created_at_index');
        });
        Schema::table('maquinarias', function (Blueprint $table) {
            $table->dropIndex('maquinarias_estado_index');
        });
        Schema::table('alquileres', function (Blueprint $table) {
            $table->dropIndex('alquileres_estado_fecha_index');
        });
        // En MySQL, el índice único pasó a sostener la FK de `ci_usuario` (MySQL
        // elimina el índice implícito de la FK cuando otro índice la cubre).
        // Se crea primero un índice simple para la FK y luego se quita el único.
        Schema::table('carritos', function (Blueprint $table) {
            $table->index('ci_usuario', 'carritos_ci_usuario_index');
        });
        Schema::table('carritos', function (Blueprint $table) {
            $table->dropUnique('carritos_usuario_producto_unique');
        });
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropForeign('cajas_user_id_foreign');
        });
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropIndex('cajas_user_id_estado_index');
        });
        Schema::table('metodoPago', function (Blueprint $table) {
            $table->dropUnique('metodopago_codigo_unique');
        });
        Schema::table('metodoPago', function (Blueprint $table) {
            $table->dropColumn(['codigo', 'activo']);
        });
        Schema::table('marca', function (Blueprint $table) {
            $table->dropUnique('marca_nombre_unique');
        });
        Schema::table('rol', function (Blueprint $table) {
            $table->dropUnique('rol_nombre_unique');
        });
        Schema::table('usuario', function (Blueprint $table) {
            $table->dropUnique('usuario_email_unique');
        });
        // Las longitudes ampliadas no se reducen al revertir: hacerlo podría
        // truncar datos. Es intencional.
    }
};
