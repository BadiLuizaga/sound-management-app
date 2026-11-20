<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Etapa 1: ESQUEMA de reservas (sin lógica de negocio todavía).
 *
 * Reglas confirmadas que este esquema soporta:
 *  - El alquiler es exclusivamente por paquetes (reserva_paquetes → paquetes).
 *  - La duración se expresa en horas (reservas.horas, precio por hora).
 *  - La confirmación es independiente del pago: `estado` (ciclo de la
 *    reserva) y `estado_pago` son columnas separadas.
 *  - Los equipos se reservan SOLO al confirmar: `reserva_equipos` se rellena
 *    en la confirmación, nunca mientras el cliente está pagando. Una reserva
 *    pendiente no tiene filas en `reserva_equipos`.
 *  - Liberación al cancelar o expirar: `reserva_equipos.estado = 'liberado'`.
 *  - Sin sobreventa: la disponibilidad de una maquinaria en un intervalo es
 *    `maquinarias.cantidad_total` menos la suma de `reserva_equipos` en estado
 *    'reservado' que se solapan. La confirmación deberá hacerse en una
 *    transacción con bloqueo de fila (SELECT ... FOR UPDATE) sobre las
 *    maquinarias afectadas. El índice de reserva_equipos sirve esa consulta.
 *    MySQL no tiene restricciones de exclusión por rango, así que el bloqueo
 *    es responsabilidad de la capa de servicio (etapa de reservas).
 *
 * Moneda operativa: BOB.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Unidades disponibles de cada equipo (por defecto 1: mantiene el
        // significado actual de "una fila = una máquina").
        Schema::table('maquinarias', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_total')->default(1)->after('estado');
        });

        Schema::create('paquetes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->decimal('precio_hora', 12, 2);
            $table->char('moneda', 3)->default('BOB');
            $table->unsignedInteger('horas_minimas')->default(1);
            $table->unsignedInteger('horas_maximas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('activo');
        });

        Schema::create('paquete_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->foreignId('maquinaria_id')->constrained('maquinarias')->restrictOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            $table->timestamps();

            $table->unique(['paquete_id', 'maquinaria_id']);
        });

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->integer('ci_cliente');
            $table->enum('estado', ['pendiente', 'confirmada', 'en_curso', 'finalizada', 'cancelada', 'expirada', 'rechazada'])
                ->default('pendiente');
            $table->enum('estado_pago', ['pendiente', 'parcial', 'pagado', 'reembolsado'])
                ->default('pendiente');
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_fin');
            $table->unsignedInteger('horas');
            $table->decimal('total', 12, 2)->default(0);
            $table->char('moneda', 3)->default('BOB');
            $table->dateTime('expira_at')->nullable();
            $table->dateTime('confirmada_at')->nullable();
            $table->dateTime('cancelada_at')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->integer('ci_registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('ci_cliente')->references('ci')->on('usuario')->restrictOnDelete();
            $table->foreign('ci_registrado_por')->references('ci')->on('usuario')->nullOnDelete();

            $table->index(['estado', 'fecha_inicio']);
            $table->index(['fecha_inicio', 'fecha_fin']);
            $table->index(['estado', 'expira_at']);
            $table->index('ci_cliente');
        });

        Schema::create('reserva_paquetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->foreignId('paquete_id')->constrained('paquetes')->restrictOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            // Precio congelado al momento de reservar (el paquete puede cambiar después)
            $table->decimal('precio_hora', 12, 2);
            $table->unsignedInteger('horas');
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            $table->unique(['reserva_id', 'paquete_id']);
        });

        // Equipos bloqueados por una reserva CONFIRMADA (asignación por intervalo)
        Schema::create('reserva_equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->foreignId('maquinaria_id')->constrained('maquinarias')->restrictOnDelete();
            $table->unsignedInteger('cantidad');
            // Copia del intervalo de la reserva para consultar solapamientos sin JOIN
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_fin');
            $table->enum('estado', ['reservado', 'liberado'])->default('reservado');
            $table->dateTime('liberado_at')->nullable();
            $table->timestamps();

            $table->unique(['reserva_id', 'maquinaria_id']);
            $table->index(['maquinaria_id', 'estado', 'fecha_inicio', 'fecha_fin'], 'reserva_equipos_disponibilidad_index');
        });

        // Restricciones CHECK (MySQL 8.0.16+ las aplica). SQLite no permite
        // añadirlas a tablas existentes; ahí se cubren con la validación.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reservas ADD CONSTRAINT reservas_intervalo_check CHECK (fecha_fin > fecha_inicio)');
            DB::statement('ALTER TABLE reservas ADD CONSTRAINT reservas_horas_check CHECK (horas > 0)');
            DB::statement('ALTER TABLE reservas ADD CONSTRAINT reservas_total_check CHECK (total >= 0)');
            DB::statement('ALTER TABLE paquetes ADD CONSTRAINT paquetes_precio_check CHECK (precio_hora >= 0)');
            DB::statement('ALTER TABLE paquetes ADD CONSTRAINT paquetes_horas_check CHECK (horas_minimas > 0 AND (horas_maximas IS NULL OR horas_maximas >= horas_minimas))');
            DB::statement('ALTER TABLE paquete_items ADD CONSTRAINT paquete_items_cantidad_check CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE reserva_paquetes ADD CONSTRAINT reserva_paquetes_cantidad_check CHECK (cantidad > 0 AND horas > 0 AND subtotal >= 0)');
            DB::statement('ALTER TABLE reserva_equipos ADD CONSTRAINT reserva_equipos_cantidad_check CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE reserva_equipos ADD CONSTRAINT reserva_equipos_intervalo_check CHECK (fecha_fin > fecha_inicio)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_equipos');
        Schema::dropIfExists('reserva_paquetes');
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('paquete_items');
        Schema::dropIfExists('paquetes');

        if (Schema::hasColumn('maquinarias', 'cantidad_total')) {
            Schema::table('maquinarias', function (Blueprint $table) {
                $table->dropColumn('cantidad_total');
            });
        }
    }
};
