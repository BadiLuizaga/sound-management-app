<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Models\TipoCambio;
use Modules\Finance\Models\TipoCambioRevision;
use Modules\Finance\Support\Dinero;

/**
 * Tipos de cambio explícitos (BOB por 1 USD), vigencia diaria.
 * Nunca existe una tasa por defecto: si no hay tasa para la fecha, no hay conversión.
 */
class TipoCambioService
{
    public const BASE = 'USD';
    public const COTIZADA = 'BOB';

    public function hoy(): Carbon
    {
        return Carbon::now(config('finance.zona_horaria', 'America/La_Paz'))->startOfDay();
    }

    /**
     * Tasa con fecha de aplicación HOY (La Paz). La tasa de un día anterior no se hereda.
     */
    public function vigenteParaHoy(string $base = self::BASE): ?TipoCambio
    {
        return TipoCambio::where('moneda_base', $base)
            ->where('moneda_cotizada', self::COTIZADA)
            ->whereDate('fecha_aplicacion', $this->hoy()->toDateString())
            ->first();
    }

    /**
     * BOB → moneda base: round(bob / tasa, 2) HALF_UP.
     */
    public function convertir(Dinero $bob, TipoCambio $tasa): Dinero
    {
        if ($bob->moneda !== self::COTIZADA) {
            throw new \LogicException('Solo se convierten importes en BOB.');
        }

        return $bob->convertirDividiendo((string) $tasa->tasa, $tasa->moneda_base);
    }

    /**
     * Registrar la tasa de una fecha (solo administrador; la autorización la hace el controlador).
     */
    public function registrar(Usuario $usuario, string $tasa, string $fecha, ?string $fuente = null): TipoCambio
    {
        $this->validarTasa($tasa);

        return DB::transaction(function () use ($usuario, $tasa, $fecha, $fuente) {
            $existe = TipoCambio::where('moneda_base', self::BASE)->where('moneda_cotizada', self::COTIZADA)
                ->whereDate('fecha_aplicacion', $fecha)->lockForUpdate()->exists();
            if ($existe) {
                throw new \DomainException("Ya existe una tasa para el {$fecha}.");
            }

            $tc = TipoCambio::create([
                'moneda_base' => self::BASE,
                'moneda_cotizada' => self::COTIZADA,
                'tasa' => $tasa,
                'fecha_aplicacion' => $fecha,
                'fuente' => $fuente,
                'ci_registrado_por' => $usuario->ci,
            ]);

            Bitacora::registrar('INSERTAR', 'tipos_cambio', (int) $tc->id, "Tasa BOB por 1 USD = {$tasa} para el {$fecha}.");

            return $tc;
        });
    }

    /**
     * Modificar una tasa: solo si ningún pago la usó. Deja revisión y bitácora.
     */
    public function modificar(TipoCambio $tc, string $nuevaTasa, string $motivo, Usuario $usuario): TipoCambio
    {
        $this->validarTasa($nuevaTasa);
        if (trim($motivo) === '') {
            throw new \DomainException('El motivo de la modificación es obligatorio.');
        }

        return DB::transaction(function () use ($tc, $nuevaTasa, $motivo, $usuario) {
            $tc = TipoCambio::whereKey($tc->id)->lockForUpdate()->firstOrFail();

            if ($tc->enUso()) {
                throw new \DomainException('La tasa ya fue usada por pagos: no se puede modificar. Registre la tasa de otro día.');
            }

            TipoCambioRevision::create([
                'tipo_cambio_id' => $tc->id,
                'tasa_anterior' => $tc->tasa,
                'tasa_nueva' => $nuevaTasa,
                'motivo' => $motivo,
                'ci_usuario' => $usuario->ci,
            ]);

            $anterior = $tc->tasa;
            $tc->update(['tasa' => $nuevaTasa]);

            Bitacora::registrar('ACTUALIZAR', 'tipos_cambio', (int) $tc->id, "Tasa del {$tc->fecha_aplicacion->toDateString()}: {$anterior} → {$nuevaTasa}. Motivo: {$motivo}");

            return $tc;
        });
    }

    private function validarTasa(string $tasa): void
    {
        if (!preg_match('/^\d{1,6}(\.\d{1,6})?$/', $tasa) || (float) $tasa <= 0) {
            throw new \DomainException('La tasa debe ser un número positivo con hasta 6 decimales.');
        }
    }
}
