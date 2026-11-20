<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Aplicadores\AplicadorPago;
use Modules\Finance\Aplicadores\AplicadorReserva;
use Modules\Finance\Aplicadores\AplicadorVentaEnLinea;
use Modules\Finance\Aplicadores\AplicadorVentaPresencial;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Exceptions\AplicacionFallida;
use Modules\Finance\Exceptions\PagoNoDisponible;
use Modules\Finance\Exceptions\TransicionInvalida;
use Modules\Finance\Gateways\GatewayIncierto;
use Modules\Finance\Gateways\GatewayRechazo;
use Modules\Finance\Gateways\PaymentGateway;
use Modules\Finance\Gateways\Resultados\ResultadoOrden;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\PagoEvento;
use Modules\Finance\Support\Dinero;
use Modules\Sales\Models\Caja;
use Modules\Sales\Models\MetodoPago;

/**
 * Ciclo de vida de los pagos.
 *
 * Reglas que garantiza:
 *  - Toda transición se valida (EstadoPago / EstadoAplicacion) y queda en pago_eventos.
 *  - Ninguna llamada al proveedor se hace dentro de una transacción: el estado se
 *    confirma ANTES ('verificando') y DESPUÉS ('recibido') de cada llamada.
 *  - Un pago 'recibido' se confirma antes de aplicarlo; un fallo de la aplicación
 *    solo cambia estado_aplicacion a 'requiere_revision'.
 *  - Lo que diga el navegador nunca cambia un estado: solo la respuesta verificada
 *    del proveedor, un webhook verificado o una persona autorizada.
 */
class PagoService
{
    public const TIPO_VENTA_EN_LINEA = 'venta_en_linea';
    public const TIPO_VENTA_PRESENCIAL = 'venta_presencial';
    public const TIPO_RESERVA = 'reserva';

    /** Métodos presenciales que requieren verificación manual del ingreso */
    public const REQUIEREN_VERIFICACION = [MetodoPago::TRANSFERENCIA, MetodoPago::QR];

    /** Métodos presenciales con referencia obligatoria y única */
    public const REQUIEREN_REFERENCIA = [MetodoPago::TARJETA, MetodoPago::TRANSFERENCIA, MetodoPago::QR];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TipoCambioService $tiposCambio,
    ) {
    }

    // ───────────────────────── Núcleo ─────────────────────────

    public function bloquear(int $pagoId): Pago
    {
        return Pago::whereKey($pagoId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Único punto de cambio de estado. Debe llamarse con el pago bloqueado.
     */
    public function transicionar(
        Pago $pago,
        string $tipoEvento,
        ?EstadoPago $estado = null,
        ?EstadoAplicacion $aplicacion = null,
        array $cambios = [],
        array $datos = [],
        ?Usuario $usuario = null,
    ): Pago {
        $estadoAnterior = $pago->estado;
        $aplicacionAnterior = $pago->estado_aplicacion;

        if ($estado !== null && $estado !== $estadoAnterior && !$estadoAnterior->puedePasarA($estado)) {
            throw new TransicionInvalida("Transición no permitida: {$estadoAnterior->value} → {$estado->value}.");
        }
        if ($aplicacion !== null && $aplicacion !== $aplicacionAnterior && !$aplicacionAnterior->puedePasarA($aplicacion)) {
            throw new TransicionInvalida("Transición de aplicación no permitida: {$aplicacionAnterior->value} → {$aplicacion->value}.");
        }

        if ($estado !== null) {
            $cambios['estado'] = $estado;
        }
        if ($aplicacion !== null) {
            $cambios['estado_aplicacion'] = $aplicacion;
        }

        if ($cambios !== []) {
            $pago->fill($cambios)->save();
        }

        $this->evento($pago, $tipoEvento, $datos, $usuario, $estadoAnterior, $aplicacionAnterior);

        return $pago;
    }

    public function evento(
        Pago $pago,
        string $tipo,
        array $datos = [],
        ?Usuario $usuario = null,
        ?EstadoPago $estadoAnterior = null,
        ?EstadoAplicacion $aplicacionAnterior = null,
    ): void {
        PagoEvento::create([
            'pago_id' => $pago->id,
            'tipo' => $tipo,
            'estado_anterior' => $estadoAnterior?->value,
            'estado_nuevo' => $pago->estado->value,
            'estado_aplicacion_anterior' => $aplicacionAnterior?->value,
            'estado_aplicacion_nuevo' => $pago->estado_aplicacion->value,
            'datos' => $datos === [] ? null : $datos,
            'ci_usuario' => $usuario?->ci ?? auth()->user()?->ci,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    // ───────────────────────── En línea (PayPal) ─────────────────────────

    /**
     * @return array{disponible: bool, motivo: ?string, tasa: ?\Modules\Finance\Models\TipoCambio}
     */
    public function disponibilidadEnLinea(): array
    {
        if (!$this->gateway->configurado()) {
            return ['disponible' => false, 'motivo' => 'El pago en línea no está configurado.', 'tasa' => null];
        }
        if (!MetodoPago::disponible(MetodoPago::PAYPAL)) {
            return ['disponible' => false, 'motivo' => 'El método de pago en línea no está disponible.', 'tasa' => null];
        }
        $tasa = $this->tiposCambio->vigenteParaHoy($this->gateway->moneda());
        if (!$tasa) {
            return ['disponible' => false, 'motivo' => 'No hay un tipo de cambio registrado para hoy.', 'tasa' => null];
        }

        return ['disponible' => true, 'motivo' => null, 'tasa' => $tasa];
    }

    /**
     * Crea (o recupera, por idempotencia) el intento de pago en línea con la tasa congelada.
     * No reserva stock ni equipos.
     */
    public function iniciarEnLinea(Usuario $usuario, Dinero $total, array $snapshot, string $claveIdempotencia, ?int $reservaId = null): Pago
    {
        $disponibilidad = $this->disponibilidadEnLinea();
        if (!$disponibilidad['disponible']) {
            throw new PagoNoDisponible($disponibilidad['motivo']);
        }
        if ($total->moneda !== 'BOB' || !$total->esPositivo()) {
            throw new PagoNoDisponible('El importe a pagar no es válido.');
        }

        $tasa = $disponibilidad['tasa'];

        return DB::transaction(function () use ($usuario, $total, $snapshot, $claveIdempotencia, $reservaId, $tasa) {
            $existente = Pago::where('clave_idempotencia', $claveIdempotencia)->lockForUpdate()->first();
            if ($existente) {
                if ((string) $existente->ci_usuario !== (string) $usuario->ci) {
                    throw new PagoNoDisponible('La clave de idempotencia ya está en uso.');
                }
                return $existente;
            }

            $proveedor = $this->tiposCambio->convertir($total, $tasa);

            $pago = Pago::create([
                'uuid' => (string) Str::uuid(),
                'clave_idempotencia' => $claveIdempotencia,
                'canal' => Pago::CANAL_EN_LINEA,
                'proveedor' => Pago::PROVEEDOR_PAYPAL,
                'metodo_pago_id' => MetodoPago::idPorCodigo(MetodoPago::PAYPAL),
                'estado' => EstadoPago::Iniciado,
                'estado_aplicacion' => EstadoAplicacion::Pendiente,
                'monto' => $total->decimal(),
                'moneda' => 'BOB',
                'monto_proveedor' => $proveedor->decimal(),
                'moneda_proveedor' => $proveedor->moneda,
                'tipo_cambio_id' => $tasa->id,
                'tasa_aplicada' => $tasa->tasa,
                'ci_usuario' => $usuario->ci,
                'reserva_id' => $reservaId,
                'snapshot' => $snapshot,
                'expira_at' => now()->addMinutes((int) config('finance.expiracion_minutos', 30)),
            ]);

            $this->evento($pago, 'creado', [
                'monto_bob' => $total->decimal(),
                'monto_proveedor' => $proveedor->decimal() . ' ' . $proveedor->moneda,
                'tasa' => (string) $tasa->tasa,
                'fecha_tasa' => $tasa->fecha_aplicacion->toDateString(),
            ], $usuario);

            return $pago;
        });
    }

    /**
     * Crea la orden en PayPal (idempotente por PayPal-Request-Id = "orden-{uuid}").
     */
    public function crearOrden(Pago $pago): Pago
    {
        $pago->refresh();
        if ($pago->estado !== EstadoPago::Iniciado) {
            if ($pago->referencia_externa) {
                return $pago; // ya tiene orden (reintento del cliente)
            }
            throw new TransicionInvalida('El pago no admite crear una orden en su estado actual.');
        }

        try {
            $orden = $this->gateway->crearOrden($pago);
        } catch (GatewayRechazo $e) {
            DB::transaction(function () use ($pago, $e) {
                $p = $this->bloquear($pago->id);
                if ($p->estado === EstadoPago::Iniciado) {
                    $this->transicionar($p, 'orden_rechazada', EstadoPago::Fallido, null, [], ['codigo' => $e->codigo]);
                }
            });
            throw new PagoNoDisponible('PayPal no pudo crear la orden de pago.');
        } catch (GatewayIncierto $e) {
            // Crear una orden no cobra nada: el pago sigue 'iniciado' y el reintento
            // con la misma PayPal-Request-Id devuelve la misma orden.
            $this->evento($pago, 'orden_incierta', ['error' => $e->getMessage()]);
            throw new PagoNoDisponible('No se pudo contactar con PayPal. Intenta nuevamente.');
        }

        return DB::transaction(function () use ($pago, $orden) {
            $p = $this->bloquear($pago->id);
            if ($p->estado === EstadoPago::Iniciado) {
                $this->transicionar($p, 'orden_creada', EstadoPago::OrdenCreada, null, [
                    'referencia_externa' => $orden->orderId,
                    'respuesta_proveedor' => $orden->respuesta,
                ], ['order_id' => $orden->orderId, 'estado_orden' => $orden->estadoOrden]);
            }
            return $p;
        });
    }

    /**
     * Captura en el servidor. El pago pasa a 'verificando' ANTES de llamar a
     * PayPal; si la respuesta es incierta, se queda así hasta la conciliación.
     */
    public function capturar(Pago $pago, ?Usuario $usuario = null): Pago
    {
        $preparado = DB::transaction(function () use ($pago, $usuario) {
            $p = $this->bloquear($pago->id);

            if ($p->estado->dineroRecibido()) {
                return ['pago' => $p, 'llamar' => false]; // idempotente
            }

            return match ($p->estado) {
                EstadoPago::OrdenCreada => [
                    'pago' => $this->transicionar($p, 'captura_solicitada', EstadoPago::Verificando, null, [], [], $usuario),
                    'llamar' => true,
                ],
                // Reintento tras un resultado incierto: misma PayPal-Request-Id, sin doble cobro
                EstadoPago::Verificando => ['pago' => $p, 'llamar' => true],
                default => throw new TransicionInvalida('El pago no se puede capturar en su estado actual.'),
            };
        });

        $p = $preparado['pago'];
        if (!$preparado['llamar']) {
            return $p;
        }

        try {
            $resultado = $this->gateway->capturarOrden((string) $p->referencia_externa, 'captura-' . $p->uuid);
        } catch (GatewayIncierto $e) {
            $this->evento($p->fresh(), 'captura_incierta', ['error' => $e->getMessage()], $usuario);
            return $p->fresh();
        } catch (GatewayRechazo $e) {
            if ($e->codigo === 'UNPROCESSABLE_ENTITY' && str_contains(json_encode($e->respuesta), 'ORDER_ALREADY_CAPTURED')) {
                return $this->conciliarConProveedor($p->fresh());
            }

            return DB::transaction(function () use ($p, $e, $usuario) {
                $x = $this->bloquear($p->id);
                if ($x->estado === EstadoPago::Verificando) {
                    $this->transicionar($x, 'captura_rechazada', EstadoPago::Fallido, null, [], ['codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $usuario);
                }
                return $x;
            });
        }

        $this->registrarResultadoProveedor($p, $resultado, 'captura', $usuario);

        return $this->aplicar($p->fresh());
    }

    /**
     * Consulta la orden en el proveedor y registra lo que realmente ocurrió
     * (conciliación y webhooks). Nunca expira un pago incierto.
     */
    public function conciliarConProveedor(Pago $pago): Pago
    {
        $resultado = $this->gateway->consultarOrden((string) $pago->referencia_externa);
        $this->registrarResultadoProveedor($pago, $resultado, 'conciliacion');

        return $this->aplicar($pago->fresh());
    }

    /**
     * Registra el resultado del proveedor. Si PayPal capturó el dinero, el pago
     * queda 'recibido' aunque algo no cuadre (en ese caso, en revisión).
     */
    public function registrarResultadoProveedor(Pago $pago, ResultadoOrden $resultado, string $origen, ?Usuario $usuario = null): Pago
    {
        return DB::transaction(function () use ($pago, $resultado, $origen, $usuario) {
            $p = $this->bloquear($pago->id);

            if ($p->estado->dineroRecibido()) {
                return $p; // ya registrado (otra vía llegó primero)
            }

            if (!$resultado->capturaCompletada()) {
                if (in_array($resultado->estadoCaptura, ['DECLINED', 'FAILED'], true) && $p->estado->puedePasarA(EstadoPago::Fallido)) {
                    return $this->transicionar($p, "captura_rechazada_{$origen}", EstadoPago::Fallido, null, [], ['estado_captura' => $resultado->estadoCaptura], $usuario);
                }
                if ($resultado->estadoCaptura === 'PENDING' && $p->estado === EstadoPago::OrdenCreada) {
                    return $this->transicionar($p, "captura_pendiente_{$origen}", EstadoPago::Verificando, null, [], [], $usuario);
                }
                $this->evento($p, "sin_captura_{$origen}", ['estado_orden' => $resultado->estadoOrden, 'estado_captura' => $resultado->estadoCaptura], $usuario);
                return $p;
            }

            $discrepancias = [];
            if ($resultado->orderId !== $p->referencia_externa) {
                $discrepancias[] = 'la orden no coincide';
            }
            if ($resultado->customId !== $p->uuid) {
                $discrepancias[] = 'custom_id distinto';
            }
            $esperado = $p->importeProveedor();
            if (!$esperado || !$resultado->montoCapturado || !$resultado->montoCapturado->igualA($esperado)) {
                $discrepancias[] = 'monto o moneda distintos (esperado ' . ($esperado?->decimal() ?? '?') . ' ' . $p->moneda_proveedor
                    . ', capturado ' . ($resultado->montoCapturado?->decimal() ?? '?') . ' ' . ($resultado->montoCapturado?->moneda ?? '') . ')';
            }

            $cambios = [
                'referencia_captura' => $resultado->capturaId,
                'respuesta_proveedor' => $resultado->respuesta,
                'recibido_at' => now(),
            ];

            if ($discrepancias !== []) {
                $cambios['motivo_revision'] = 'Cobro recibido con discrepancias: ' . implode('; ', $discrepancias) . '.';
                Log::warning('Pago recibido con discrepancias', ['pago' => $p->uuid, 'discrepancias' => $discrepancias]);

                return $this->transicionar($p, "recibido_con_discrepancias_{$origen}", EstadoPago::Recibido, EstadoAplicacion::RequiereRevision, $cambios, ['discrepancias' => $discrepancias], $usuario);
            }

            return $this->transicionar($p, "recibido_{$origen}", EstadoPago::Recibido, null, $cambios, ['captura_id' => $resultado->capturaId], $usuario);
        });
    }

    // ───────────────────────── Aplicación ─────────────────────────

    /**
     * Aplica un pago recibido a su destino, con reintentos ante bloqueos.
     * Si falla, el pago SE CONSERVA y queda en revisión.
     */
    public function aplicar(Pago $pago, bool $desdeRevision = false, ?Usuario $usuario = null): Pago
    {
        $pago->refresh();
        $origenPermitido = $desdeRevision ? EstadoAplicacion::RequiereRevision : EstadoAplicacion::Pendiente;

        if (!$pago->estado->dineroRecibido() || $pago->estado === EstadoPago::Revertido || $pago->estado_aplicacion !== $origenPermitido) {
            return $pago;
        }

        $aplicador = $this->aplicadorPara($pago);

        try {
            DB::transaction(function () use ($pago, $aplicador, $origenPermitido, $usuario) {
                $p = $this->bloquear($pago->id);
                if ($p->estado_aplicacion !== $origenPermitido) {
                    return;
                }
                $resultado = $aplicador->aplicar($p);
                $this->transicionar($p, 'aplicado', null, EstadoAplicacion::Aplicado, $resultado + ['aplicado_at' => now()], $resultado, $usuario);
            }, max(1, (int) config('finance.reintentos_aplicacion', 3)));
        } catch (AplicacionFallida $e) {
            $this->marcarRevision($pago, $e->getMessage(), $usuario);
        } catch (\Throwable $e) {
            Log::error('Error técnico al aplicar un pago', ['pago' => $pago->uuid, 'error' => $e->getMessage()]);
            $this->marcarRevision($pago, 'Error técnico al generar la venta/reserva (' . class_basename($e) . ').', $usuario);
        }

        return $pago->fresh();
    }

    private function marcarRevision(Pago $pago, string $motivo, ?Usuario $usuario): void
    {
        DB::transaction(function () use ($pago, $motivo, $usuario) {
            $p = $this->bloquear($pago->id);
            if ($p->estado_aplicacion === EstadoAplicacion::Pendiente) {
                $this->transicionar($p, 'error_aplicacion', null, EstadoAplicacion::RequiereRevision, ['motivo_revision' => $motivo], ['motivo' => $motivo], $usuario);
            } else {
                $this->evento($p, 'error_aplicacion', ['motivo' => $motivo], $usuario);
                $p->update(['motivo_revision' => $motivo]);
            }
            Bitacora::registrar('PAGO_EN_REVISION', 'pagos', (int) $p->id, "Pago {$p->uuid} recibido pero no aplicado: {$motivo}");
        });
    }

    public function aplicadorPara(Pago $pago): AplicadorPago
    {
        return match ($pago->snapshot['tipo'] ?? null) {
            self::TIPO_VENTA_EN_LINEA => app(AplicadorVentaEnLinea::class),
            self::TIPO_VENTA_PRESENCIAL => app(AplicadorVentaPresencial::class),
            self::TIPO_RESERVA => app(AplicadorReserva::class),
            default => throw new AplicacionFallida('Tipo de destino del pago desconocido.'),
        };
    }

    public function reintentarAplicacion(Pago $pago, Usuario $usuario): Pago
    {
        $this->evento($pago, 'reintento_aplicacion_solicitado', [], $usuario);

        return $this->aplicar($pago, true, $usuario);
    }

    public function aplicarManual(Pago $pago, Usuario $usuario, string $motivo): Pago
    {
        if (trim($motivo) === '') {
            throw new \DomainException('El motivo es obligatorio.');
        }

        return DB::transaction(function () use ($pago, $usuario, $motivo) {
            $p = $this->bloquear($pago->id);
            if (!$p->estado->dineroRecibido()) {
                throw new TransicionInvalida('Solo se puede cerrar manualmente un pago recibido.');
            }
            $this->transicionar($p, 'aplicado_manual', null, EstadoAplicacion::AplicadoManual, ['aplicado_at' => now()], ['motivo' => $motivo], $usuario);
            Bitacora::registrar('PAGO_APLICADO_MANUAL', 'pagos', (int) $p->id, "Pago {$p->uuid} cerrado manualmente: {$motivo}");

            return $p;
        });
    }

    // ───────────────────────── Presencial ─────────────────────────

    /**
     * Registra un cobro en caja.
     *  - Efectivo y tarjeta: 'recibido' y la venta se genera en la MISMA transacción
     *    (si la venta falla, no se registra nada: el dinero no se cobró).
     *  - Transferencia y QR: 'pendiente_verificacion'; la venta se genera al verificar.
     *
     * @param array{referencia?: ?string, efectivo_recibido?: ?string, clave_idempotencia: string} $datos
     */
    public function registrarPresencial(Usuario $cajero, string $codigoMetodo, Dinero $total, array $snapshot, array $datos): Pago
    {
        if (!in_array($codigoMetodo, [MetodoPago::EFECTIVO, MetodoPago::TARJETA, MetodoPago::TRANSFERENCIA, MetodoPago::QR], true)) {
            throw new \DomainException('Método de pago no permitido en caja.');
        }
        if (!$total->esPositivo()) {
            throw new \DomainException('El importe debe ser mayor que cero.');
        }

        $metodoId = MetodoPago::idPorCodigo($codigoMetodo);
        $clave = trim((string) ($datos['clave_idempotencia'] ?? ''));
        if ($clave === '') {
            throw new \DomainException('Falta la clave de idempotencia de la operación.');
        }

        $referencia = isset($datos['referencia']) ? strtoupper(trim((string) $datos['referencia'])) : '';
        if (in_array($codigoMetodo, self::REQUIEREN_REFERENCIA, true) && $referencia === '') {
            throw new \DomainException('La referencia de la operación es obligatoria para este método de pago.');
        }

        $efectivo = null;
        $cambio = null;
        if ($codigoMetodo === MetodoPago::EFECTIVO) {
            $efectivo = Dinero::deDecimal((string) ($datos['efectivo_recibido'] ?? '0'), 'BOB');
            if ($efectivo->centavos < $total->centavos) {
                throw new \DomainException('El efectivo recibido es menor que el total.');
            }
            $cambio = $efectivo->restar($total);
        }

        $requiereVerificacion = in_array($codigoMetodo, self::REQUIEREN_VERIFICACION, true);

        return DB::transaction(function () use ($cajero, $metodoId, $total, $snapshot, $clave, $referencia, $efectivo, $cambio, $requiereVerificacion) {
            $existente = Pago::where('clave_idempotencia', $clave)->lockForUpdate()->first();
            if ($existente) {
                if ((string) $existente->ci_registrado_por !== (string) $cajero->ci) {
                    throw new \DomainException('La operación ya fue registrada por otro usuario.');
                }
                return $existente; // doble envío del POS
            }

            if ($referencia !== '' && Pago::where('metodo_pago_id', $metodoId)->where('referencia_externa', $referencia)->exists()) {
                throw new \DomainException("La referencia {$referencia} ya fue registrada para este método de pago.");
            }

            $caja = Caja::where('user_id', $cajero->ci)->where('estado', 'abierta')->lockForUpdate()->first();
            if (!$caja) {
                throw new \DomainException('Debes realizar la apertura de tu caja antes de registrar cobros.');
            }

            $pago = Pago::create([
                'uuid' => (string) Str::uuid(),
                'clave_idempotencia' => $clave,
                'canal' => Pago::CANAL_PRESENCIAL,
                'proveedor' => Pago::PROVEEDOR_MANUAL,
                'metodo_pago_id' => $metodoId,
                'estado' => $requiereVerificacion ? EstadoPago::PendienteVerificacion : EstadoPago::Recibido,
                'estado_aplicacion' => EstadoAplicacion::Pendiente,
                'monto' => $total->decimal(),
                'moneda' => 'BOB',
                'monto_recibido_efectivo' => $efectivo?->decimal(),
                'cambio_entregado' => $cambio?->decimal(),
                'referencia_externa' => $referencia !== '' ? $referencia : null,
                'ci_usuario' => $snapshot['ci_cliente'] ?? null,
                'ci_registrado_por' => $cajero->ci,
                'caja_id' => $caja->id,
                'snapshot' => $snapshot,
                'recibido_at' => $requiereVerificacion ? null : now(),
            ]);

            $this->evento($pago, $requiereVerificacion ? 'registrado_pendiente_verificacion' : 'registrado_recibido', [
                'referencia' => $referencia !== '' ? $referencia : null,
            ], $cajero);

            if (!$requiereVerificacion) {
                // Efectivo/tarjeta: la venta va en la misma transacción
                $resultado = $this->aplicadorPara($pago)->aplicar($pago);
                $this->transicionar($pago, 'aplicado', null, EstadoAplicacion::Aplicado, $resultado + ['aplicado_at' => now()], $resultado, $cajero);
            }

            return $pago;
        });
    }

    /**
     * Verifica un ingreso por transferencia o QR (administrador / encargado).
     */
    public function verificar(Pago $pago, Usuario $verificador): Pago
    {
        DB::transaction(function () use ($pago, $verificador) {
            $p = $this->bloquear($pago->id);
            if ($p->estado !== EstadoPago::PendienteVerificacion) {
                throw new TransicionInvalida('El pago no está pendiente de verificación.');
            }
            $this->transicionar($p, 'verificado', EstadoPago::Recibido, null, [
                'ci_verificado_por' => $verificador->ci,
                'verificado_at' => now(),
                'recibido_at' => now(),
            ], [], $verificador);
        });

        // La venta se genera tras confirmar el dinero; si falla, el pago queda en revisión
        return $this->aplicar($pago->fresh(), false, $verificador);
    }

    public function rechazar(Pago $pago, Usuario $verificador, string $motivo): Pago
    {
        if (trim($motivo) === '') {
            throw new \DomainException('El motivo del rechazo es obligatorio.');
        }

        return DB::transaction(function () use ($pago, $verificador, $motivo) {
            $p = $this->bloquear($pago->id);
            if ($p->estado !== EstadoPago::PendienteVerificacion) {
                throw new TransicionInvalida('El pago no está pendiente de verificación.');
            }

            return $this->transicionar($p, 'rechazado', EstadoPago::Rechazado, EstadoAplicacion::NoAplica, [
                'ci_verificado_por' => $verificador->ci,
                'verificado_at' => now(),
                'motivo_rechazo' => $motivo,
            ], ['motivo' => $motivo], $verificador);
        });
    }
}
