<?php

namespace Modules\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Models\CajaEvento;
use Modules\Finance\Models\MovimientoCaja;
use Modules\Finance\Support\Dinero;
use Modules\Sales\Models\Caja;

/**
 * Apertura, cierre y movimientos manuales de caja (Etapa 3).
 *
 *  - Una sola caja abierta por usuario (comprobación con bloqueo + índice único
 *    sobre la columna generada cajas.abierta_de).
 *  - El cierre bloquea la fila de la caja, igual que el cobro presencial: un
 *    cobro y un cierre simultáneos se serializan; tras el cierre, el cobro
 *    recibe "caja cerrada". El cierre es idempotente por clave.
 *  - Cierre supervisado (D2): administrador o encargado cierran la caja de
 *    otro; quedan registrados el titular y el responsable.
 *  - Sin reaperturas (D4): las correcciones son movimientos nuevos que
 *    referencian al original o a la caja cerrada; nada se edita ni se borra.
 */
class CajaService
{
    public function __construct(private readonly ArqueoService $arqueo)
    {
    }

    public function cajaAbiertaDe(Usuario $usuario): ?Caja
    {
        return Caja::where('user_id', $usuario->ci)->where('estado', Caja::ABIERTA)->first();
    }

    public function abrir(Usuario $titular, Dinero $fondo): Caja
    {
        if ($fondo->moneda !== 'BOB' || $fondo->centavos < 0) {
            throw new \DomainException('El fondo de apertura debe ser un importe en BOB mayor o igual a cero.');
        }

        try {
            return DB::transaction(function () use ($titular, $fondo) {
                $existe = Caja::where('user_id', $titular->ci)->where('estado', Caja::ABIERTA)->lockForUpdate()->exists();
                if ($existe) {
                    throw new \DomainException('Ya tienes una caja abierta. Realiza el cierre antes de abrir otra.');
                }

                $caja = Caja::create([
                    'user_id' => $titular->ci,
                    'monto_apertura' => $fondo->decimal(),
                    'estado' => Caja::ABIERTA,
                    'fecha_apertura' => now(),
                    'version_arqueo' => Caja::ARQUEO_POR_METODO,
                ]);

                $this->evento($caja, CajaEvento::APERTURA, $titular, ['fondo' => $fondo->decimal()]);
                Bitacora::registrar('CAJA_APERTURA', 'cajas', (int) $caja->id, "Apertura de caja #{$caja->id} con fondo {$fondo->decimal()} BOB.");

                return $caja;
            });
        } catch (QueryException $e) {
            // Dos aperturas simultáneas: el índice único de la base rechaza la segunda
            if ($this->esViolacionDeUnicidad($e)) {
                throw new \DomainException('Ya tienes una caja abierta. Realiza el cierre antes de abrir otra.');
            }
            throw $e;
        }
    }

    /**
     * Cierra la caja con el efectivo contado. Si el responsable no es el titular,
     * es un cierre supervisado (requiere cash.close_any).
     */
    public function cerrar(Caja $caja, Usuario $responsable, Dinero $contado, ?string $observaciones, string $clave): Caja
    {
        $observaciones = $observaciones !== null ? trim($observaciones) : '';
        $clave = trim($clave);
        if ($clave === '') {
            throw new \DomainException('Falta la clave de idempotencia del cierre.');
        }
        if ($contado->moneda !== 'BOB' || $contado->centavos < 0) {
            throw new \DomainException('El efectivo contado debe ser un importe en BOB mayor o igual a cero.');
        }

        $supervisado = (string) $caja->user_id !== (string) $responsable->ci;
        if ($supervisado && !Gate::forUser($responsable)->allows('cash.close_any')) {
            $this->evento($caja, CajaEvento::INTENTO_RECHAZADO, $responsable, ['accion' => 'cierre', 'motivo' => 'caja ajena sin permiso']);
            throw new \DomainException('No puedes cerrar una caja de otro usuario.');
        }

        return DB::transaction(function () use ($caja, $responsable, $contado, $observaciones, $clave, $supervisado) {
            $c = Caja::whereKey($caja->id)->lockForUpdate()->firstOrFail();

            if (!$c->estaAbierta()) {
                if ($c->clave_cierre !== null && hash_equals((string) $c->clave_cierre, $clave)) {
                    return $c; // doble envío del mismo cierre
                }
                throw new TransicionInvalida('La caja ya está cerrada. Las cajas cerradas no se reabren.');
            }

            $arqueo = $this->arqueo->calcular($c);
            $diferencia = $contado->restar($arqueo->efectivoEsperado);

            if ($diferencia->centavos !== 0 && $observaciones === '') {
                throw new \DomainException("Hay una diferencia de {$diferencia->decimal()} BOB: la observación es obligatoria.");
            }
            if ($supervisado && $observaciones === '') {
                throw new \DomainException('En un cierre supervisado la observación es obligatoria.');
            }

            $foto = $arqueo->toArray() + [
                'efectivo_contado' => $contado->decimal(),
                'diferencia' => $diferencia->decimal(),
                'titular' => (int) $c->user_id,
                'cerrado_por' => (int) $responsable->ci,
                'cierre_supervisado' => $supervisado,
            ];

            $c->update([
                'estado' => Caja::CERRADA,
                'fecha_cierre' => now(),
                'version_arqueo' => Caja::ARQUEO_POR_METODO,
                'monto_cierre' => $contado->decimal(),      // columna heredada: efectivo real
                'diferencia' => $diferencia->decimal(),
                'efectivo_esperado' => $arqueo->efectivoEsperado->decimal(),
                'efectivo_contado' => $contado->decimal(),
                'resumen' => $foto,
                'ci_cerrado_por' => $responsable->ci,
                'cierre_supervisado' => $supervisado,
                'observaciones' => $observaciones !== '' ? $observaciones : null,
                'clave_cierre' => $clave,
            ]);

            $this->evento($c, $supervisado ? CajaEvento::CIERRE_SUPERVISADO : CajaEvento::CIERRE, $responsable, [
                'efectivo_esperado' => $arqueo->efectivoEsperado->decimal(),
                'efectivo_contado' => $contado->decimal(),
                'diferencia' => $diferencia->decimal(),
                'pendientes' => count($arqueo->pendientes),
                'observaciones' => $observaciones !== '' ? $observaciones : null,
            ]);
            Bitacora::registrar(
                $supervisado ? 'CAJA_CIERRE_SUPERVISADO' : 'CAJA_CIERRE',
                'cajas',
                (int) $c->id,
                "Cierre de caja #{$c->id} (titular {$c->user_id}, cerrada por {$responsable->ci}): esperado {$arqueo->efectivoEsperado->decimal()}, contado {$contado->decimal()}, diferencia {$diferencia->decimal()} BOB."
            );

            return $c;
        });
    }

    /**
     * Ingreso o egreso manual en la caja abierta del usuario.
     * Con $cajaCorregida: corrección del arqueo de una caja ya cerrada (D4).
     */
    public function registrarMovimiento(Usuario $usuario, string $tipo, Dinero $monto, string $motivo, string $clave, ?Caja $cajaCorregida = null): MovimientoCaja
    {
        if (!in_array($tipo, [MovimientoCaja::INGRESO, MovimientoCaja::EGRESO], true)) {
            throw new \DomainException('Tipo de movimiento no válido.');
        }
        if ($monto->moneda !== 'BOB' || !$monto->esPositivo()) {
            throw new \DomainException('El importe del movimiento debe ser mayor que cero (BOB).');
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \DomainException('El motivo del movimiento es obligatorio.');
        }
        $permiso = $tipo === MovimientoCaja::INGRESO ? 'cash.movements.income' : 'cash.movements.expense';
        if (!Gate::forUser($usuario)->allows($permiso)) {
            throw new \DomainException($tipo === MovimientoCaja::INGRESO ? 'No puedes registrar ingresos de caja.' : 'No puedes registrar egresos de caja.');
        }
        if ($cajaCorregida !== null && !Gate::forUser($usuario)->allows('cash.movements.expense')) {
            throw new \DomainException('No puedes registrar correcciones de cajas cerradas.');
        }

        return DB::transaction(function () use ($usuario, $tipo, $monto, $motivo, $clave, $cajaCorregida) {
            if ($existente = $this->movimientoExistente($clave, $usuario)) {
                return $existente;
            }

            $caja = Caja::where('user_id', $usuario->ci)->where('estado', Caja::ABIERTA)->lockForUpdate()->first();
            if (!$caja) {
                throw new \DomainException('Debes tener tu caja abierta para registrar movimientos de efectivo.');
            }

            if ($cajaCorregida !== null) {
                $corregida = Caja::whereKey($cajaCorregida->id)->lockForUpdate()->firstOrFail();
                if ($corregida->estaAbierta()) {
                    throw new \DomainException('Una caja abierta no se corrige: registra el movimiento en ella directamente.');
                }
            }

            return $this->insertarMovimiento($caja, $usuario, $tipo, $monto, $motivo, $clave, null, $cajaCorregida?->id);
        });
    }

    /**
     * Movimiento correctivo inverso. El original queda intacto.
     * Si la caja original sigue abierta, la reversión va en ella; si ya cerró,
     * va en la caja abierta del responsable y referencia a la caja original.
     */
    public function revertirMovimiento(MovimientoCaja $original, Usuario $usuario, string $motivo, string $clave): MovimientoCaja
    {
        if (!Gate::forUser($usuario)->allows('cash.movements.revert')) {
            throw new \DomainException('No puedes revertir movimientos de caja.');
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \DomainException('El motivo de la reversión es obligatorio.');
        }

        return DB::transaction(function () use ($original, $usuario, $motivo, $clave) {
            if ($existente = $this->movimientoExistente($clave, $usuario)) {
                return $existente;
            }

            $o = MovimientoCaja::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($o->revierte_id !== null) {
                throw new \DomainException('Un movimiento de reversión no se revierte: registra un movimiento nuevo.');
            }
            if (MovimientoCaja::where('revierte_id', $o->id)->exists()) {
                throw new \DomainException('Este movimiento ya fue revertido.');
            }

            $cajaOriginal = Caja::whereKey($o->caja_id)->lockForUpdate()->firstOrFail();
            if ($cajaOriginal->estaAbierta()) {
                $destino = $cajaOriginal;
                $corregida = null;
            } else {
                $destino = Caja::where('user_id', $usuario->ci)->where('estado', Caja::ABIERTA)->lockForUpdate()->first();
                if (!$destino) {
                    throw new \DomainException('La caja del movimiento ya cerró: abre tu caja para registrar la reversión.');
                }
                $corregida = $cajaOriginal->id;
            }

            $inverso = $o->tipo === MovimientoCaja::INGRESO ? MovimientoCaja::EGRESO : MovimientoCaja::INGRESO;

            return $this->insertarMovimiento($destino, $usuario, $inverso, $o->importe(), $motivo, $clave, $o->id, $corregida);
        });
    }

    public function evento(Caja $caja, string $tipo, ?Usuario $responsable, array $datos = []): CajaEvento
    {
        return CajaEvento::create([
            'caja_id' => $caja->id,
            'tipo' => $tipo,
            'ci_titular' => $caja->user_id,
            'ci_responsable' => $responsable?->ci,
            'datos' => $datos ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    // ───────────────────────── Internos ─────────────────────────

    private function insertarMovimiento(Caja $caja, Usuario $usuario, string $tipo, Dinero $monto, string $motivo, string $clave, ?int $revierteId, ?int $cajaCorregidaId): MovimientoCaja
    {
        if ($tipo === MovimientoCaja::EGRESO) {
            $disponible = $this->arqueo->calcular($caja)->efectivoEsperado;
            if ($monto->mayorQue($disponible)) {
                throw new \DomainException("El egreso supera el efectivo esperado en la caja ({$disponible->decimal()} BOB).");
            }
        }

        $mov = MovimientoCaja::create([
            'uuid' => (string) Str::uuid(),
            'clave_idempotencia' => $clave,
            'caja_id' => $caja->id,
            'tipo' => $tipo,
            'monto' => $monto->decimal(),
            'moneda' => 'BOB',
            'motivo' => $motivo,
            'revierte_id' => $revierteId,
            'caja_corregida_id' => $cajaCorregidaId,
            'ci_registrado_por' => $usuario->ci,
            'ip' => request()?->ip(),
        ]);

        $tipoEvento = $revierteId !== null ? CajaEvento::REVERSION : ($tipo === MovimientoCaja::INGRESO ? CajaEvento::INGRESO : CajaEvento::EGRESO);
        $datos = ['movimiento' => $mov->uuid, 'tipo' => $tipo, 'monto' => $monto->decimal(), 'motivo' => $motivo, 'revierte' => $revierteId, 'caja_corregida' => $cajaCorregidaId];
        $this->evento($caja, $tipoEvento, $usuario, $datos);

        if ($cajaCorregidaId !== null) {
            $this->evento(Caja::findOrFail($cajaCorregidaId), CajaEvento::CORRECCION_RECIBIDA, $usuario, $datos + ['registrado_en_caja' => $caja->id]);
        }

        Bitacora::registrar(
            'CAJA_' . strtoupper($tipoEvento),
            'movimientos_caja',
            (int) $mov->id,
            "Movimiento {$tipo} de {$monto->decimal()} BOB en la caja #{$caja->id}: {$motivo}"
                . ($revierteId !== null ? " (revierte el movimiento #{$revierteId})" : '')
                . ($cajaCorregidaId !== null ? " (corrige la caja cerrada #{$cajaCorregidaId})" : '')
        );

        return $mov;
    }

    private function movimientoExistente(string $clave, Usuario $usuario): ?MovimientoCaja
    {
        if (trim($clave) === '') {
            throw new \DomainException('Falta la clave de idempotencia del movimiento.');
        }
        $existente = MovimientoCaja::where('clave_idempotencia', $clave)->first();
        if ($existente && (string) $existente->ci_registrado_por !== (string) $usuario->ci) {
            throw new \DomainException('La operación ya fue registrada por otro usuario.');
        }

        return $existente;
    }

    private function esViolacionDeUnicidad(QueryException $e): bool
    {
        $codigo = (string) ($e->errorInfo[1] ?? '');

        return $codigo === '1062' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
