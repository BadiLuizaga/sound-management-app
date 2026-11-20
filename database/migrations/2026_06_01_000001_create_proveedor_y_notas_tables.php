<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 1.
 *
 * Tablas que el código usa (Proveedor, NotaCompra, DetalleNotaCompra,
 * FacturaVenta → NotaVenta, DetalleFacturaVenta → detalleNotaVenta) y que
 * hasta ahora solo existían en un volcado SQL externo. Aquí se crea
 * ÚNICAMENTE la estructura, con los mismos tipos que esperan los modelos.
 * No se inserta ningún dato.
 *
 * Debe ejecutarse después de la migración base (cliente, empleado,
 * metodoPago, producto) y antes de `devoluciones` (FK a NotaVenta).
 * Los documentos de venta/compra no se borran en cascada (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('proveedor')) {
            Schema::create('proveedor', function (Blueprint $table) {
                $table->integer('ci')->primary();
                $table->string('nombre', 100);
                $table->text('descripcion');
                $table->integer('telefono');
                $table->string('correo', 50)->nullable();
                $table->string('direccion', 100)->nullable();
            });
        }

        if (!Schema::hasTable('NotaVenta')) {
            Schema::create('NotaVenta', function (Blueprint $table) {
                $table->integer('nro')->primary();
                $table->dateTime('fecha')->useCurrent();
                $table->decimal('total', 10, 2)->default(0);
                $table->integer('ci_cliente')->nullable();
                $table->integer('ci_empleado')->nullable();
                $table->integer('id_pago')->nullable();

                $table->foreign('ci_cliente')->references('ci')->on('cliente')->restrictOnDelete();
                $table->foreign('ci_empleado')->references('ci')->on('empleado')->restrictOnDelete();
                $table->foreign('id_pago')->references('id')->on('metodoPago')->cascadeOnUpdate()->restrictOnDelete();

                $table->index('fecha');
                $table->index(['ci_empleado', 'fecha']);
            });
        }

        if (!Schema::hasTable('detalleNotaVenta')) {
            Schema::create('detalleNotaVenta', function (Blueprint $table) {
                $table->integer('nro_factura');
                $table->integer('id_producto');
                $table->decimal('precio_unitario', 10, 2);
                $table->integer('cantidad');
                $table->decimal('descuento', 10, 2)->default(0);

                $table->primary(['nro_factura', 'id_producto']);
                $table->foreign('nro_factura')->references('nro')->on('NotaVenta')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('id_producto')->references('idproducto')->on('producto')->cascadeOnUpdate()->restrictOnDelete();
            });
        }

        if (!Schema::hasTable('NotaCompra')) {
            Schema::create('NotaCompra', function (Blueprint $table) {
                $table->integer('nro')->primary();
                $table->dateTime('fecha')->useCurrent();
                $table->decimal('total', 10, 2)->default(0);
                $table->integer('ci_proveedor')->nullable();
                $table->integer('id_pago')->nullable();

                $table->foreign('ci_proveedor')->references('ci')->on('proveedor')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('id_pago')->references('id')->on('metodoPago')->cascadeOnUpdate()->restrictOnDelete();

                $table->index('fecha');
            });
        }

        if (!Schema::hasTable('detalleNotaCompra')) {
            Schema::create('detalleNotaCompra', function (Blueprint $table) {
                $table->integer('nro_factura');
                $table->integer('id_producto');
                $table->decimal('precio_unitario', 10, 2);
                $table->integer('cantidad');

                $table->primary(['nro_factura', 'id_producto']);
                $table->foreign('nro_factura')->references('nro')->on('NotaCompra')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('id_producto')->references('idproducto')->on('producto')->cascadeOnUpdate()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('detalleNotaCompra');
        Schema::dropIfExists('NotaCompra');
        Schema::dropIfExists('detalleNotaVenta');
        Schema::dropIfExists('NotaVenta');
        Schema::dropIfExists('proveedor');
    }
};
