<?php

namespace Modules\Rentals\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Rentals\Enums\EstadoReserva;
use Modules\Rentals\Exceptions\CotizacionInvalida;
use Modules\Rentals\Exceptions\TransicionReservaInvalida;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Models\ReservaEvento;

/**
 * Ciclo de la reserva (Etapa 4, paso 3): crear, confirmar automáticamente,
 * rechazar, cancelar y vencer. Punto único para ERP, POS y portal.
 *
 * Reglas:
 *  - Solo paquetes y horas (CotizadorReserva); precio congelado en reserva_paquetes.
 *  - La confirmación es AUTOMÁTICA según disponibilidad e INDEPENDIENTE del pago:
 *    nunca se consulta ni se modifica `estado_pago`.
 *  - Los equipos se reservan SOLO al confirmar (reserva_equipos), en una
 *    transacción que bloquea primero la reserva y después las maquinarias
 *    afectadas (FOR UPDATE, ordenadas por id) y recalcula la disponibilidad
 *    dentro del bloqueo. Crear o pagar nunca bloquea equipos.
 *  - Sin disponibilidad se rechaza al instante con el detalle de faltantes (D2).
 *  - Cancelar libera las unidades. SOUND POS no gestiona reembolsos: los pagos
 *    de la reserva no se tocan.
 *  - Cada cambio deja un evento en reserva_eventos (solo inserción) y en la bitácora.
 */
class ReservaService
{
    public const CANALES = [Reserva::CANAL_ERP, Reserva::CANAL_POS, Reserva::CANAL_PORTAL];

    /**
     * Reintentos de la confirmación si MySQL detecta un interbloqueo (p. ej.,
     * por bloqueos de rango de InnoDB entre confirmaciones de equipos vecinos).
     * La transacción se deshace entera antes de reintentar: no hay sobreventa.
     */
    public const INTENTOS_CONFIRMAR = 3;

    public function __construct(
        private readonly CotizadorReserva $cotizador,
        private readonly DisponibilidadService $disponibilidad,
    ) {
    }

    // ───────────────────────── Crear ─────────────────────────

    /**
     * Crea la reserva (pendiente, sin equipos) y la confirma o rechaza de
     * inmediato. Idempotente por clave: un doble envío devuelve la misma reserva.
     *
     * @param array<int, array{paquete_id:int|string, cantidad?:int|string}> $lineas
     * @param array{observaciones?:?string, lugar_entrega?:?string} $datos
     */
    public function crear(
        Usuario $registradoPor,
        int|string $ciCliente,
        array $lineas,
        CarbonInterface|string $inicio,
        CarbonInterface|string $fin,
        string $canal,
        string $claveIdempotencia,
        array $datos = [],
    ): Reserva {
        $clave = trim($claveIdempotencia);
        if ($clave === '' || mb_strlen($clave) > 80) {
            throw new \DomainException('Falta la clave de idempotencia de la operación (máximo 80 caracteres).');
        }
        if ($existente = $this->porClave($clave, $registradoPor)) {
            return $existente;
        }
        if (!in_array($canal, self::CANALES, true)) {
            throw new \DomainException('Canal de reserva no válido.');
        }
        $cliente = Usuario::find($ciCliente);
        if (!$cliente) {
            throw new \DomainException('El cliente no existe.');
        }
        if ($canal === Reserva::CANAL_PORTAL && (string) $registradoPor->ci !== (string) $cliente->ci) {
            throw new \DomainException('En el portal, cada cliente solo puede reservar a su nombre.');
        }

        $cotizacion = $this->cotizador->cotizar($lineas, $inicio, $fin);
        if ($cotizacion->inicio->lessThan(CarbonImmutable::now()->startOfMinute())) {
            throw new CotizacionInvalida('La reserva no puede empezar en el pasado.');
        }

        try {
            $reserva = DB::transaction(function () use ($registradoPor, $cliente, $cotizacion, $canal, $clave, $datos) {
                $reserva = Reserva::create([
                    'codigo' => $this->nuevoCodigo(),
                    'ci_cliente' => $cliente->ci,
                    'estado' => Reserva::ESTADO_PENDIENTE,
                    'estado_pago' => Reserva::PAGO_PENDIENTE,
                    'canal' => $canal,
                    'fecha_inicio' => $cotizacion->inicio->toDateTimeString(),
                    'fecha_fin' => $cotizacion->fin->toDateTimeString(),
                    'horas' => $cotizacion->horas,
                    'total' => $cotizacion->total->decimal(),
                    'moneda' => $cotizacion->total->moneda,
                    'expira_at' => CarbonImmutable::now()->addMinutes($this->minutosExpiracion())->toDateTimeString(),
                    'observaciones' => $datos['observaciones'] ?? null,
                    'lugar_entrega' => $datos['lugar_entrega'] ?? null,
                    'clave_idempotencia' => $clave,
                    'ci_registrado_por' => $registradoPor->ci,
                ]);
                foreach ($cotizacion->filasReservaPaquetes() as $fila) {
                    $reserva->paquetes()->create($fila);
                }

                $this->evento($reserva, ReservaEvento::CREADA, null, EstadoReserva::Pendiente, $registradoPor, [
                    'canal' => $canal,
                    'total' => $cotizacion->total->decimal(),
                    'horas' => $cotizacion->horas,
                    'paquetes' => $cotizacion->filasReservaPaquetes(),
                ]);

                return $reserva;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Doble envío simultáneo con la misma clave: gana el primero
            if ($existente = $this->porClave($clave, $registradoPor)) {
                return $existente;
            }
            throw $e;
        }

        return $this->confirmar($reserva, $registradoPor);
    }

    // ───────────────────────── Confirmar ─────────────────────────

    /**
     * Confirma una reserva pendiente si hay unidades suficientes; si no, la
     * rechaza (D2). Idempotente: una reserva ya confirmada se devuelve sin cambios.
     */
    public function confirmar(Reserva|int $reserva, ?Usuario $responsable = null): Reserva
    {
        $id = $reserva instanceof Reserva ? (int) $reserva->id : $reserva;

        return DB::transaction(function () use ($id, $responsable) {
            $r = Reserva::whereKey($id)->lockForUpdate()->firstOrFail();
            $estado = EstadoReserva::from($r->estado);

            if ($estado === EstadoReserva::Confirmada) {
                return $r;
            }
            if ($estado !== EstadoReserva::Pendiente) {
                throw new TransicionReservaInvalida("No se puede confirmar una reserva en estado «{$r->estado}».");
            }
            if ($r->expira_at !== null && $r->expira_at->lessThanOrEqualTo(now())) {
                $this->marcarExpirada($r, $responsable);

                return $r;
            }

            $requerimientos = $this->requerimientos($r);
            if ($requerimientos === []) {
                $this->rechazar($r, $responsable, 'La reserva no incluye equipos.', []);

                return $r;
            }

            // Punto de serialización: maquinarias FOR UPDATE y recálculo dentro del bloqueo
            $disp = $this->disponibilidad->consultar($requerimientos, $r->fecha_inicio, $r->fecha_fin, $r->id, bloquear: true);

            if (!$disp->alcanza()) {
                $this->rechazar($r, $responsable, (string) $disp->motivo(), $disp->faltantes());

                return $r;
            }

            foreach ($requerimientos as $maquinariaId => $cantidad) {
                ReservaEquipo::create([
                    'reserva_id' => $r->id,
                    'maquinaria_id' => $maquinariaId,
                    'cantidad' => $cantidad,
                    'fecha_inicio' => $r->fecha_inicio->toDateTimeString(),
                    'fecha_fin' => $r->fecha_fin->toDateTimeString(),
                    'estado' => ReservaEquipo::ESTADO_RESERVADO,
                ]);
            }

            $r->forceFill([
                'estado' => Reserva::ESTADO_CONFIRMADA,
                'confirmada_at' => now(),
                'expira_at' => null,
            ])->save();

            $this->evento($r, ReservaEvento::CONFIRMADA, EstadoReserva::Pendiente, EstadoReserva::Confirmada, $responsable, [
                'equipos' => $requerimientos,
                'margen_minutos' => $disp->margenMinutos,
            ]);
            Bitacora::registrar('RESERVA_CONFIRMADA', 'reservas', $r->id, "Reserva {$r->codigo} confirmada automáticamente ({$r->fecha_inicio} – {$r->fecha_fin}).");

            return $r;
        }, self::INTENTOS_CONFIRMAR);
    }

    // ───────────────────────── Cancelar ─────────────────────────

    /**
     * Cancela una reserva pendiente o confirmada y libera sus unidades.
     * No toca los pagos (SOUND POS no gestiona reembolsos).
     */
    public function cancelar(Reserva|int $reserva, Usuario $responsable, string $motivo): Reserva
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \DomainException('El motivo de la cancelación es obligatorio.');
        }
        $id = $reserva instanceof Reserva ? (int) $reserva->id : $reserva;

        return DB::transaction(function () use ($id, $responsable, $motivo) {
            $r = Reserva::whereKey($id)->lockForUpdate()->firstOrFail();
            $estado = EstadoReserva::from($r->estado);

            if ($estado === EstadoReserva::Cancelada) {
                return $r;
            }
            $this->exigirTransicion($r, $estado, EstadoReserva::Cancelada, 'cancelar');

            $liberadas = $this->liberarEquipos($r);
            $r->forceFill([
                'estado' => Reserva::ESTADO_CANCELADA,
                'cancelada_at' => now(),
                'motivo_cancelacion' => Str::limit($motivo, 250, '…'),
                'expira_at' => null,
            ])->save();

            $this->evento($r, ReservaEvento::CANCELADA, $estado, EstadoReserva::Cancelada, $responsable, [
                'motivo' => $motivo,
                'unidades_liberadas' => $liberadas,
                // SOUND POS no devuelve dinero: el estado de pago queda solo como referencia
                'estado_pago' => $r->estado_pago,
            ]);
            Bitacora::registrar('RESERVA_CANCELADA', 'reservas', $r->id, "Reserva {$r->codigo} cancelada por CI {$responsable->ci}: {$motivo}");

            return $r;
        });
    }

    // ───────────────────────── Vencer ─────────────────────────

    /**
     * Marca 'expirada' cada reserva pendiente cuyo plazo venció (tarea
     * `reservas:expirar`). Cada una en su transacción, con bloqueo y nueva
     * comprobación del estado. Devuelve cuántas venció.
     */
    public function expirarVencidas(): int
    {
        $ids = Reserva::where('estado', Reserva::ESTADO_PENDIENTE)
            ->whereNotNull('expira_at')
            ->where('expira_at', '<=', now())
            ->orderBy('id')
            ->pluck('id');

        $vencidas = 0;
        foreach ($ids as $id) {
            $vencidas += DB::transaction(function () use ($id) {
                $r = Reserva::whereKey($id)->lockForUpdate()->first();
                if (!$r || $r->estado !== Reserva::ESTADO_PENDIENTE || $r->expira_at === null || $r->expira_at->greaterThan(now())) {
                    return 0;
                }
                $this->marcarExpirada($r, null);

                return 1;
            });
        }

        return $vencidas;
    }

    // ───────────────────────── Internos ─────────────────────────

    /** Unidades por equipo según los paquetes de la reserva: Σ item.cantidad × paquete.cantidad. */
    public function requerimientos(Reserva $reserva): array
    {
        $filas = DB::table('reserva_paquetes as rp')
            ->join('paquete_items as pi', 'pi.paquete_id', '=', 'rp.paquete_id')
            ->where('rp.reserva_id', $reserva->id)
            ->groupBy('pi.maquinaria_id')
            ->orderBy('pi.maquinaria_id')
            ->selectRaw('pi.maquinaria_id AS maquinaria_id, SUM(pi.cantidad * rp.cantidad) AS unidades')
            ->get();

        $requerimientos = [];
        foreach ($filas as $f) {
            $requerimientos[(int) $f->maquinaria_id] = (int) $f->unidades;
        }

        return $requerimientos;
    }

    private function rechazar(Reserva $r, ?Usuario $responsable, string $motivo, array $faltantes): void
    {
        $r->forceFill([
            'estado' => Reserva::ESTADO_RECHAZADA,
            'motivo_cancelacion' => Str::limit($motivo, 250, '…'),
            'expira_at' => null,
        ])->save();

        $this->evento($r, ReservaEvento::RECHAZADA, EstadoReserva::Pendiente, EstadoReserva::Rechazada, $responsable, [
            'motivo' => $motivo,
            'faltantes' => $faltantes,
        ]);
        Bitacora::registrar('RESERVA_RECHAZADA', 'reservas', $r->id, "Reserva {$r->codigo} rechazada: {$motivo}");
    }

    private function marcarExpirada(Reserva $r, ?Usuario $responsable): void
    {
        $liberadas = $this->liberarEquipos($r);
        $r->forceFill(['estado' => Reserva::ESTADO_EXPIRADA])->save();

        $this->evento($r, ReservaEvento::EXPIRADA, EstadoReserva::Pendiente, EstadoReserva::Expirada, $responsable, [
            'expira_at' => $r->expira_at?->toDateTimeString(),
            'unidades_liberadas' => $liberadas,
        ]);
        Bitacora::registrar('RESERVA_EXPIRADA', 'reservas', $r->id, "Reserva {$r->codigo} vencida sin confirmar.");
    }

    private function liberarEquipos(Reserva $r): int
    {
        $unidades = (int) ReservaEquipo::where('reserva_id', $r->id)->where('estado', ReservaEquipo::ESTADO_RESERVADO)->sum('cantidad');
        ReservaEquipo::where('reserva_id', $r->id)
            ->where('estado', ReservaEquipo::ESTADO_RESERVADO)
            ->update(['estado' => ReservaEquipo::ESTADO_LIBERADO, 'liberado_at' => now(), 'updated_at' => now()]);

        return $unidades;
    }

    private function exigirTransicion(Reserva $r, EstadoReserva $desde, EstadoReserva $hacia, string $accion): void
    {
        if (!$desde->puedePasarA($hacia)) {
            throw new TransicionReservaInvalida("No se puede {$accion} una reserva en estado «{$r->estado}».");
        }
    }

    private function evento(Reserva $r, string $tipo, ?EstadoReserva $anterior, ?EstadoReserva $nuevo, ?Usuario $responsable, array $datos = []): ReservaEvento
    {
        return ReservaEvento::create([
            'reserva_id' => $r->id,
            'tipo' => $tipo,
            'estado_anterior' => $anterior?->value,
            'estado_nuevo' => $nuevo?->value,
            'ci_responsable' => $responsable?->ci,
            'datos' => $datos,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    private function porClave(string $clave, Usuario $registradoPor): ?Reserva
    {
        $existente = Reserva::where('clave_idempotencia', $clave)->first();
        if ($existente && (string) $existente->ci_registrado_por !== (string) $registradoPor->ci) {
            throw new \DomainException('La operación ya fue registrada por otro usuario.');
        }

        return $existente;
    }

    private function nuevoCodigo(): string
    {
        do {
            $codigo = 'RES-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        } while (Reserva::where('codigo', $codigo)->exists());

        return $codigo;
    }

    private function minutosExpiracion(): int
    {
        return max(1, (int) config('rentals.expiracion_pendiente_minutos', 15));
    }
}
