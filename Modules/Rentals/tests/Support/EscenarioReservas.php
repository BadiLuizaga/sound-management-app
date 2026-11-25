<?php

namespace Modules\Rentals\Tests\Support;

use Database\Factories\UsuarioFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Rentals\Models\Maquinaria;
use Modules\Rentals\Models\MaquinariaBloqueo;
use Modules\Rentals\Models\Paquete;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Sales\Models\MetodoPago;

/**
 * Datos ficticios para las pruebas de reservas (solo en la base de pruebas).
 * Las reservas confirmadas se insertan directamente: ReservaService (paso 3)
 * todavía no existe.
 */
trait EscenarioReservas
{
    private ?Usuario $encargadoEscenario = null;

    protected function encargado(): Usuario
    {
        return $this->encargadoEscenario ??= UsuarioFactory::new()->conRol('Encargado')->create();
    }

    protected function equipo(int $unidades = 1, string $estado = 'disponible', string $nombre = 'Parlante activo'): Maquinaria
    {
        return Maquinaria::create([
            'codigo' => 'EQ-' . Str::upper(Str::random(6)),
            'nombre' => $nombre,
            'precio_hora' => '50.00',
            'precio_dia' => '300.00',
            'estado' => $estado,
            'cantidad_total' => $unidades,
        ]);
    }

    /**
     * @param array<int, int> $items maquinaria_id => unidades por paquete
     */
    protected function paquete(array $items, string $precioHora = '100.00', array $extra = []): Paquete
    {
        $paquete = Paquete::create($extra + [
            'codigo' => 'PQ-' . Str::upper(Str::random(6)),
            'nombre' => 'Paquete ' . Str::random(4),
            'precio_hora' => $precioHora,
            'horas_minimas' => 1,
            'activo' => true,
        ]);
        foreach ($items as $maquinariaId => $cantidad) {
            $paquete->items()->create(['maquinaria_id' => $maquinariaId, 'cantidad' => $cantidad]);
        }

        return $paquete->fresh('items');
    }

    /**
     * Reserva con sus unidades bloqueadas en reserva_equipos.
     *
     * @param array<int, int> $equipos maquinaria_id => unidades
     */
    protected function reservaConEquipos(array $equipos, string $inicio, string $fin, string $estado = Reserva::ESTADO_CONFIRMADA, array $extra = [], string $estadoEquipos = ReservaEquipo::ESTADO_RESERVADO): Reserva
    {
        $reserva = Reserva::create($extra + [
            'codigo' => 'R-' . Str::upper(Str::random(8)),
            'ci_cliente' => UsuarioFactory::new()->cliente()->create()->ci,
            'estado' => $estado,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'horas' => max(1, (int) ceil((strtotime($fin) - strtotime($inicio)) / 3600)),
            'total' => '100.00',
        ]);
        foreach ($equipos as $maquinariaId => $cantidad) {
            ReservaEquipo::create([
                'reserva_id' => $reserva->id,
                'maquinaria_id' => $maquinariaId,
                'cantidad' => $cantidad,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
                'estado' => $estadoEquipos,
            ]);
        }

        return $reserva;
    }

    /**
     * Reserva PENDIENTE (sin equipos) con un paquete, lista para confirmar.
     * El plazo vence en un día para que no expire durante la prueba.
     */
    protected function reservaPendiente(Paquete $paquete, int $cantidad, string $inicio, string $fin, array $extra = []): Reserva
    {
        $horas = (int) ((strtotime($fin) - strtotime($inicio)) / 3600);
        $reserva = Reserva::create($extra + [
            'codigo' => 'R-' . Str::upper(Str::random(8)),
            'ci_cliente' => UsuarioFactory::new()->cliente()->create()->ci,
            'estado' => Reserva::ESTADO_PENDIENTE,
            'canal' => Reserva::CANAL_ERP,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'horas' => $horas,
            'total' => number_format((float) $paquete->precio_hora * $horas * $cantidad, 2, '.', ''),
            'expira_at' => now()->addDay(),
        ]);
        $reserva->paquetes()->create([
            'paquete_id' => $paquete->id,
            'cantidad' => $cantidad,
            'precio_hora' => $paquete->precio_hora,
            'horas' => $horas,
            'subtotal' => $reserva->total,
        ]);

        return $reserva;
    }

    /** Alquiler heredado (tabla alquileres) con un detalle por equipo. */
    protected function alquilerHeredado(array $maquinariaIds, string $inicio, string $finEstimado, string $estado = 'activo'): int
    {
        $id = DB::table('alquileres')->insertGetId([
            'ci_cliente' => UsuarioFactory::new()->cliente()->create()->ci,
            'ci_empleado' => $this->encargado()->ci,
            'fecha_inicio' => $inicio,
            'fecha_fin_estimada' => $finEstimado,
            'monto_garantia' => 0,
            'total_estimado' => '100.00',
            'estado' => $estado,
            'metodo_pago_id' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($maquinariaIds as $maquinariaId) {
            DB::table('alquiler_detalles')->insert([
                'alquiler_id' => $id,
                'maquinaria_id' => $maquinariaId,
                'precio_unitario' => '50.00',
                'tipo_tarifa' => 'hora',
                'tiempo_rentado' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function bloqueo(int $maquinariaId, int $cantidad, string $desde, ?string $hasta, string $estado = MaquinariaBloqueo::ESTADO_ACTIVO): MaquinariaBloqueo
    {
        return MaquinariaBloqueo::create([
            'maquinaria_id' => $maquinariaId,
            'cantidad' => $cantidad,
            'desde' => $desde,
            'hasta' => $hasta,
            'motivo' => MaquinariaBloqueo::MOTIVO_MANTENIMIENTO,
            'estado' => $estado,
            'ci_registrado_por' => $this->encargado()->ci,
        ]);
    }
}
