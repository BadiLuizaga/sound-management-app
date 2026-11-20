<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 2: libro único de pagos.
 *
 *  - `estado` (dinero) y `estado_aplicacion` (venta/reserva) son independientes.
 *  - Un pago recibido se confirma ANTES de aplicarlo; ninguna FK borra pagos en
 *    cascada (todas restrict) y el código nunca los elimina.
 *  - Importes en BOB (`monto`); el importe cobrado por el proveedor en otra
 *    moneda se guarda aparte junto con la tasa congelada.
 *  - Unicidad que impide reutilizar transacciones externas.
 */
return new class extends Migration
{
    public const ESTADOS = [
        'iniciado', 'orden_creada', 'verificando', 'pendiente_verificacion', 'recibido',
        'fallido', 'expirado', 'cancelado', 'rechazado',
        'reembolsado_parcial', 'reembolsado', 'revertido',
    ];

    public const ESTADOS_APLICACION = ['pendiente', 'aplicado', 'requiere_revision', 'aplicado_manual', 'no_aplica'];

    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('clave_idempotencia', 80)->nullable()->unique();
            $table->enum('canal', ['en_linea', 'presencial']);
            $table->string('proveedor', 30); // paypal | manual
            $table->integer('metodo_pago_id');
            $table->enum('estado', self::ESTADOS);
            $table->enum('estado_aplicacion', self::ESTADOS_APLICACION)->default('pendiente');

            $table->decimal('monto', 12, 2);
            $table->char('moneda', 3)->default('BOB');
            $table->decimal('monto_recibido_efectivo', 12, 2)->nullable();
            $table->decimal('cambio_entregado', 12, 2)->nullable();
            $table->decimal('monto_proveedor', 12, 2)->nullable();
            $table->char('moneda_proveedor', 3)->nullable();
            $table->unsignedBigInteger('tipo_cambio_id')->nullable();
            $table->decimal('tasa_aplicada', 12, 6)->nullable();

            $table->string('referencia_externa', 100)->nullable();
            $table->string('referencia_captura', 100)->nullable();

            $table->integer('ci_usuario')->nullable();
            $table->integer('ci_registrado_por')->nullable();
            $table->integer('ci_verificado_por')->nullable();
            $table->unsignedBigInteger('caja_id')->nullable();
            $table->integer('nro_factura')->nullable();
            $table->unsignedBigInteger('reserva_id')->nullable();
            $table->unsignedBigInteger('legacy_pago_externo_id')->nullable()->unique();

            $table->json('snapshot')->nullable();
            $table->json('respuesta_proveedor')->nullable();
            $table->text('motivo_revision')->nullable();
            $table->string('motivo_rechazo', 255)->nullable();

            $table->dateTime('expira_at')->nullable();
            $table->dateTime('recibido_at')->nullable();
            $table->dateTime('verificado_at')->nullable();
            $table->dateTime('aplicado_at')->nullable();
            $table->timestamps();

            $table->unique(['metodo_pago_id', 'referencia_externa'], 'pagos_metodo_referencia_unique');
            $table->unique(['proveedor', 'referencia_captura'], 'pagos_proveedor_captura_unique');

            $table->foreign('metodo_pago_id')->references('id')->on('metodoPago')->restrictOnDelete();
            $table->foreign('tipo_cambio_id')->references('id')->on('tipos_cambio')->restrictOnDelete();
            $table->foreign('ci_usuario')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_verificado_por')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('caja_id')->references('id')->on('cajas')->restrictOnDelete();
            $table->foreign('nro_factura')->references('nro')->on('NotaVenta')->restrictOnDelete();
            $table->foreign('reserva_id')->references('id')->on('reservas')->restrictOnDelete();
            $table->foreign('legacy_pago_externo_id')->references('id')->on('pagos_externos')->restrictOnDelete();

            $table->index(['estado', 'expira_at'], 'pagos_estado_expira_index');
            $table->index('estado_aplicacion', 'pagos_estado_aplicacion_index');
        });

        Schema::create('pago_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pago_id')->constrained('pagos')->restrictOnDelete();
            $table->string('tipo', 50);
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30)->nullable();
            $table->string('estado_aplicacion_anterior', 30)->nullable();
            $table->string('estado_aplicacion_nuevo', 30)->nullable();
            $table->json('datos')->nullable();
            $table->integer('ci_usuario')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ci_usuario')->references('ci')->on('usuario')->restrictOnDelete();
            $table->index(['pago_id', 'created_at'], 'pago_eventos_pago_fecha_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pagos ADD CONSTRAINT pagos_monto_check CHECK (monto > 0)');
            DB::statement('ALTER TABLE pagos ADD CONSTRAINT pagos_monto_proveedor_check CHECK (monto_proveedor IS NULL OR monto_proveedor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_eventos');
        Schema::dropIfExists('pagos');
    }
};
