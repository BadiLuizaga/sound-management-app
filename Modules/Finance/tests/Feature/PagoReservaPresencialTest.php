<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Models\Usuario;
use Modules\Audit\Models\Bitacora;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\ArqueoService;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Tests\Support\EscenarioPagosReserva;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Models\ReservaEvento;
use Modules\Rentals\Services\ReservaService;
use Modules\Sales\Models\FacturaVenta;
use Modules\Sales\Models\MetodoPago;
use Tests\TestCase;

/**
 * Etapa 4 — paso 4: anticipos y saldos de reservas EN CAJA (PagoService::registrarPresencial).
 * Integración con la caja abierta, el arqueo, la verificación de transferencias,
 * la revisión y reaplicación manual, el sobrepago y la idempotencia.
 */
class PagoReservaPresencialTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioPagosReserva;

    private Usuario $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->vendedor = $this->usuarioConRol('Vendedor');
        $this->abrirCaja($this->vendedor);
    }

    // ───────────────────────── Anticipo y saldo ─────────────────────────

    public function test_anticipo_en_efectivo_deja_la_reserva_en_parcial_sin_tocar_su_estado(): void
    {
        $r = $this->reservaCobrable('300.00');
        $equipos = ReservaEquipo::where('reserva_id', $r->id)->count();

        $pago = $this->cobrar($this->vendedor, $r, '100.00');

        $this->assertSame(EstadoPago::Recibido, $pago->estado);
        $this->assertSame(EstadoAplicacion::Aplicado, $pago->estado_aplicacion);
        $this->assertSame((int) $r->id, (int) $pago->reserva_id);
        $this->assertSame($this->abrirCaja($this->vendedor)->id, $pago->caja_id);
        $this->assertSame(PagoService::TIPO_RESERVA, $pago->snapshot['tipo']);
        $this->assertSame('anticipo', $pago->snapshot['tipo_cobro']);
        $this->assertSame((string) $r->ci_cliente, (string) $pago->ci_usuario);

        $r->refresh();
        $this->assertSame(Reserva::PAGO_PARCIAL, $r->estado_pago);
        $this->assertSame(Reserva::ESTADO_CONFIRMADA, $r->estado, 'Pagar no cambia el estado de la reserva');
        $this->assertSame($equipos, ReservaEquipo::where('reserva_id', $r->id)->count(), 'Pagar no bloquea equipos');
        $this->assertSame(0, FacturaVenta::count(), 'Un pago de reserva no genera una venta');

        $cuenta = $this->cuenta($r);
        $this->assertSame('100.00', $cuenta['cobrado']);
        $this->assertSame('200.00', $cuenta['saldo']);
        $this->assertSame('200.00', $cuenta['disponible']);

        $this->assertSame(1, ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::PAGO_REGISTRADO)->count());
        $aplicado = ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::PAGO_APLICADO)->sole();
        $this->assertSame('pendiente', $aplicado->datos['estado_pago_anterior']);
        $this->assertSame('parcial', $aplicado->datos['estado_pago_nuevo']);
        $this->assertSame((string) $this->vendedor->ci, (string) $aplicado->ci_responsable);
        $this->assertSame('confirmada', $aplicado->estado_nuevo);
        $this->assertTrue(Bitacora::where('accion', 'RESERVA_PAGO_APLICADO')->exists());
        $this->assertSame(['registrado_recibido', 'aplicado'], $pago->eventos()->pluck('tipo')->all());
    }

    public function test_el_saldo_liquida_la_reserva(): void
    {
        $r = $this->reservaCobrable('300.00');

        $this->cobrar($this->vendedor, $r, '100.00');
        $saldo = $this->cobrar($this->vendedor, $r, '200.00', 'TARJETA');

        $this->assertSame('saldo', $saldo->snapshot['tipo_cobro']);
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
        $this->assertSame('0.00', $this->cuenta($r)['saldo']);
        $this->assertSame('300.00', $this->cuenta($r)['cobrado']);
    }

    public function test_un_pago_por_el_total_es_un_saldo(): void
    {
        $r = $this->reservaCobrable('300.00');

        $pago = $this->cobrar($this->vendedor, $r, '300.00');

        $this->assertSame('saldo', $pago->snapshot['tipo_cobro']);
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
    }

    public function test_el_importe_exigible_incluye_los_cargos(): void
    {
        $r = $this->reservaCobrable('300.00', '50.00');

        $this->assertSame('350.00', $this->cuenta($r)['exigible']);
        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '350.01'), \DomainException::class, 'excede el saldo disponible');

        $this->cobrar($this->vendedor, $r, '300.00');
        $this->assertSame(Reserva::PAGO_PARCIAL, $r->fresh()->estado_pago, 'Con cargos, el total sin cargos no liquida');

        $this->cobrar($this->vendedor, $r, '50.00');
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
    }

    public function test_los_importes_se_suman_en_centavos(): void
    {
        $r = $this->reservaCobrable('100.00');

        foreach (range(1, 3) as $i) {
            $this->cobrar($this->vendedor, $r, '33.33');
        }
        $this->assertSame(Reserva::PAGO_PARCIAL, $r->fresh()->estado_pago);
        $this->assertSame('0.01', $this->cuenta($r)['saldo']);

        $this->cobrar($this->vendedor, $r, '0.01');
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
        $this->assertSame('100.00', $this->cuenta($r)['cobrado']);
    }

    public function test_efectivo_con_cambio_y_efectivo_insuficiente(): void
    {
        $r = $this->reservaCobrable('300.00');

        $pago = $this->cobrar($this->vendedor, $r, '100.00', 'EFECTIVO', ['efectivo_recibido' => '150.00']);
        $this->assertSame('150.00', (string) $pago->monto_recibido_efectivo);
        $this->assertSame('50.00', (string) $pago->cambio_entregado);

        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '100.00', 'EFECTIVO', ['efectivo_recibido' => '99.99']), \DomainException::class, 'menor que el total');
        $this->assertSame(1, Pago::count());
    }

    // ───────────────────────── Sobrepago ─────────────────────────

    public function test_sobrepago_rechazado_sin_registrar_nada(): void
    {
        $r = $this->reservaCobrable('300.00');

        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '300.01'), \DomainException::class, 'excede el saldo disponible');

        $this->assertSame(0, Pago::count());
        $this->assertSame(Reserva::PAGO_PENDIENTE, $r->fresh()->estado_pago);
        $this->assertSame(0, ReservaEvento::where('reserva_id', $r->id)->count());
    }

    public function test_una_reserva_pagada_no_admite_mas_cobros(): void
    {
        $r = $this->reservaCobrable('300.00');
        $this->cobrar($this->vendedor, $r, '300.00');

        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '0.01'), \DomainException::class, 'ya está pagada');
        $this->assertSame(1, Pago::count());
    }

    public function test_importe_cero_o_negativo_rechazado(): void
    {
        $r = $this->reservaCobrable('300.00');

        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '0.00'), \DomainException::class, 'mayor que cero');
        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '-5.00'), \DomainException::class, 'mayor que cero');
        $this->assertSame(0, Pago::count());
    }

    /** Equivalente secuencial de la prueba de concurrencia (SQLite no tiene bloqueo de filas). */
    public function test_dos_cobros_por_el_saldo_completo_el_segundo_se_rechaza(): void
    {
        $otro = $this->usuarioConRol('Vendedor');
        $this->abrirCaja($otro);
        $r = $this->reservaCobrable('300.00');

        $this->cobrar($this->vendedor, $r, '300.00');
        $this->assertThrows(fn () => $this->cobrar($otro, $r, '300.00'), \DomainException::class, 'ya está pagada');

        $this->assertSame(1, Pago::where('reserva_id', $r->id)->count());
        $this->assertSame('300.00', $this->cuenta($r)['cobrado']);
        $this->assertSame('0.00', $this->cuenta($r)['sobrepago']);
    }

    // ───────────────────────── Estados de la reserva ─────────────────────────

    public function test_solo_se_cobra_a_reservas_confirmadas_en_curso_o_finalizadas(): void
    {
        foreach ([Reserva::ESTADO_PENDIENTE, Reserva::ESTADO_RECHAZADA, Reserva::ESTADO_CANCELADA, Reserva::ESTADO_EXPIRADA] as $estado) {
            $r = $this->reservaCobrable('300.00', '0.00', $estado);
            $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '100.00'), \DomainException::class, 'no admite cobros');
        }
        $this->assertSame(0, Pago::count());

        foreach ([Reserva::ESTADO_EN_CURSO, Reserva::ESTADO_FINALIZADA] as $estado) {
            $r = $this->reservaCobrable('300.00', '0.00', $estado);
            $this->cobrar($this->vendedor, $r, '100.00');
            $this->assertSame(Reserva::PAGO_PARCIAL, $r->fresh()->estado_pago);
            $this->assertSame($estado, $r->fresh()->estado);
        }
    }

    public function test_cancelar_una_reserva_pagada_no_toca_sus_pagos(): void
    {
        $r = $this->reservaCobrable('300.00');
        $pago = $this->cobrar($this->vendedor, $r, '300.00');

        app(ReservaService::class)->cancelar($r, $this->encargado(), 'El cliente desiste');

        $this->assertSame(Reserva::ESTADO_CANCELADA, $r->fresh()->estado);
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago, 'SOUND POS no devuelve dinero');
        $this->assertSame(EstadoPago::Recibido, $pago->fresh()->estado);
        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '1.00'), \DomainException::class, 'no admite cobros');
    }

    // ───────────────────────── Caja ─────────────────────────

    public function test_sin_caja_abierta_no_se_cobra(): void
    {
        $sinCaja = $this->usuarioConRol('Vendedor');
        $r = $this->reservaCobrable('300.00');

        $this->assertThrows(fn () => $this->cobrar($sinCaja, $r, '100.00'), \DomainException::class, 'apertura de tu caja');
        $this->assertSame(0, Pago::count());
        $this->assertSame(Reserva::PAGO_PENDIENTE, $r->fresh()->estado_pago);
    }

    public function test_con_la_caja_cerrada_no_se_cobra(): void
    {
        $caja = $this->abrirCaja($this->vendedor);
        $caja->forceFill(['estado' => 'cerrada', 'fecha_cierre' => now()])->save();
        $r = $this->reservaCobrable('300.00');

        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '100.00'), \DomainException::class, 'apertura de tu caja');
    }

    public function test_los_cobros_de_reservas_entran_al_arqueo_de_la_caja(): void
    {
        $caja = $this->abrirCaja($this->vendedor); // fondo 100.00
        $r = $this->reservaCobrable('300.00');

        $this->cobrar($this->vendedor, $r, '120.00');
        $this->cobrar($this->vendedor, $r, '30.00', 'TARJETA');
        $this->cobrar($this->vendedor, $r, '50.00', 'TRANSFERENCIA');

        $arqueo = app(ArqueoService::class)->calcular($caja->fresh());
        $this->assertSame('120.00', $arqueo->efectivoCobrado->decimal());
        $this->assertSame('220.00', $arqueo->efectivoEsperado->decimal());
        $this->assertSame('30.00', $arqueo->metodos[MetodoPago::TARJETA]['recibido']->decimal());
        $this->assertSame('50.00', $arqueo->metodos[MetodoPago::TRANSFERENCIA]['pendiente']->decimal());
        $this->assertCount(1, $arqueo->pendientes);
    }

    // ───────────────────────── Transferencia / QR ─────────────────────────

    public function test_transferencia_pendiente_compromete_el_saldo_hasta_verificarla(): void
    {
        $r = $this->reservaCobrable('300.00');

        $pago = $this->cobrar($this->vendedor, $r, '300.00', 'TRANSFERENCIA');
        $this->assertSame(EstadoPago::PendienteVerificacion, $pago->estado);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $r->fresh()->estado_pago, 'Una transferencia sin verificar no cuenta');

        $cuenta = $this->cuenta($r);
        $this->assertSame('300.00', $cuenta['saldo']);
        $this->assertSame('300.00', $cuenta['comprometido']);
        $this->assertSame('0.00', $cuenta['disponible']);
        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '1.00'), \DomainException::class, 'pendientes de verificación');

        app(PagoService::class)->verificar($pago, $this->admin);

        $this->assertSame(EstadoAplicacion::Aplicado, $pago->fresh()->estado_aplicacion);
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
        $this->assertSame((string) $this->admin->ci, (string) ReservaEvento::where('tipo', ReservaEvento::PAGO_APLICADO)->sole()->ci_responsable);
    }

    public function test_transferencia_rechazada_libera_el_saldo(): void
    {
        $r = $this->reservaCobrable('300.00');
        $pago = $this->cobrar($this->vendedor, $r, '300.00', 'QR');

        app(PagoService::class)->rechazar($pago, $this->admin, 'No llegó el depósito');

        $this->assertSame(EstadoPago::Rechazado, $pago->fresh()->estado);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $r->fresh()->estado_pago);
        $this->assertSame('300.00', $this->cuenta($r)['disponible']);

        $this->cobrar($this->vendedor, $r, '300.00');
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
    }

    public function test_referencia_repetida_rechazada(): void
    {
        $r = $this->reservaCobrable('300.00');
        $this->cobrar($this->vendedor, $r, '100.00', 'TRANSFERENCIA', ['referencia' => 'TRX-123']);

        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '100.00', 'TRANSFERENCIA', ['referencia' => 'trx-123']), \DomainException::class, 'ya fue registrada');
        $this->assertSame(1, Pago::count());
    }

    // ───────────────────────── Revisión y reaplicación manual ─────────────────────────

    public function test_transferencia_verificada_tras_cancelar_la_reserva_queda_en_revision(): void
    {
        $r = $this->reservaCobrable('300.00');
        $pago = $this->cobrar($this->vendedor, $r, '300.00', 'TRANSFERENCIA');
        app(ReservaService::class)->cancelar($r, $this->encargado(), 'Cambio de fecha');

        $pago = app(PagoService::class)->verificar($pago, $this->admin);

        $this->assertSame(EstadoPago::Recibido, $pago->estado, 'El dinero recibido se conserva');
        $this->assertSame(EstadoAplicacion::RequiereRevision, $pago->estado_aplicacion);
        $this->assertStringContainsString('no admite cobros', $pago->motivo_revision);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $r->fresh()->estado_pago);
        $this->assertTrue(Bitacora::where('accion', 'PAGO_EN_REVISION')->exists());

        // Reintentar sigue fallando (la reserva sigue cancelada) y no duplica nada
        $pago = app(PagoService::class)->reintentarAplicacion($pago, $this->admin);
        $this->assertSame(EstadoAplicacion::RequiereRevision, $pago->estado_aplicacion);

        // El encargado decide cerrarlo manualmente: cuenta como aplicado
        app(PagoService::class)->aplicarManual($pago, $this->admin, 'Se mantiene a cuenta de la nueva fecha');
        $this->assertSame(EstadoAplicacion::AplicadoManual, $pago->fresh()->estado_aplicacion);
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
        $this->assertSame(1, ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::ESTADO_PAGO_ACTUALIZADO)->count());
    }

    public function test_sobrepago_al_aplicar_queda_en_revision_y_se_reaplica_tras_corregir(): void
    {
        $r = $this->reservaCobrable('300.00');
        $pago = $this->cobrar($this->vendedor, $r, '300.00', 'TRANSFERENCIA');

        // Corrección del total mientras la transferencia esperaba verificación
        $r->forceFill(['total' => '200.00'])->save();
        $pago = app(PagoService::class)->verificar($pago, $this->admin);

        $this->assertSame(EstadoPago::Recibido, $pago->estado);
        $this->assertSame(EstadoAplicacion::RequiereRevision, $pago->estado_aplicacion);
        $this->assertStringContainsString('Sobrepago', $pago->motivo_revision);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $r->fresh()->estado_pago, 'Un sobrepago nunca se aplica');
        $this->assertSame('300.00', $this->cuenta($r)['comprometido'], 'El dinero en revisión compromete el saldo');
        $this->assertSame('0.00', $this->cuenta($r)['disponible']);
        $this->assertThrows(fn () => $this->cobrar($this->vendedor, $r, '1.00'), \DomainException::class, 'excede el saldo disponible');

        // Se corrige el total y se reaplica desde la pantalla de pagos
        $r->forceFill(['total' => '300.00'])->save();
        $this->actingAs($this->admin)->post(route('finanzas.admin.pagos.reintentar', $pago))->assertSessionHas('success');

        $this->assertSame(EstadoAplicacion::Aplicado, $pago->fresh()->estado_aplicacion);
        $this->assertSame(Reserva::PAGO_PAGADO, $r->fresh()->estado_pago);
        $this->assertSame(1, ReservaEvento::where('tipo', ReservaEvento::PAGO_APLICADO)->count());
    }

    // ───────────────────────── Idempotencia ─────────────────────────

    public function test_doble_envio_con_la_misma_clave_no_duplica(): void
    {
        $r = $this->reservaCobrable('300.00');
        $datos = ['clave_idempotencia' => 'pos-reserva-0000000001'];

        $a = $this->cobrar($this->vendedor, $r, '100.00', 'EFECTIVO', $datos);
        $b = $this->cobrar($this->vendedor, $r, '100.00', 'EFECTIVO', $datos);

        $this->assertSame($a->uuid, $b->uuid);
        $this->assertSame(1, Pago::count());
        $this->assertSame('100.00', $this->cuenta($r)['cobrado']);
        $this->assertSame(1, ReservaEvento::where('tipo', ReservaEvento::PAGO_REGISTRADO)->count());
        $this->assertSame(1, ReservaEvento::where('tipo', ReservaEvento::PAGO_APLICADO)->count());
    }

    // ───────────────────────── HTTP ─────────────────────────

    public function test_endpoint_de_cobro_en_caja(): void
    {
        $r = $this->reservaCobrable('300.00');
        $url = route('finanzas.reservas.cobros.store', $r);
        $datos = [
            'id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO),
            'monto' => '100.50',
            'efectivo_recibido' => '200',
            'clave_idempotencia' => 'pos-http-000000000001',
        ];

        $res = $this->actingAs($this->vendedor)->postJson($url, $datos)->assertCreated();
        $res->assertJson(['estado' => 'recibido', 'estado_aplicacion' => 'aplicado', 'cambio' => '99.50', 'tipo_cobro' => 'anticipo']);
        $res->assertJsonPath('cuenta.saldo', '199.50');

        // Doble envío: mismo pago, 200
        $this->actingAs($this->vendedor)->postJson($url, $datos)->assertOk()->assertJsonPath('pago', $res->json('pago'));
        $this->assertSame(1, Pago::count());

        // Sobrepago y más de 2 decimales
        $this->actingAs($this->vendedor)->postJson($url, ['monto' => '199.51', 'efectivo_recibido' => '200', 'clave_idempotencia' => 'pos-http-000000000002'] + $datos)
            ->assertStatus(422)->assertJsonFragment(['message' => 'El importe de 199.51 BOB excede el saldo disponible de la reserva ' . $r->codigo . ': 199.50 BOB.']);
        $this->actingAs($this->vendedor)->postJson($url, ['monto' => '10.005', 'clave_idempotencia' => 'pos-http-000000000003'] + $datos)
            ->assertStatus(422)->assertJsonValidationErrors('monto');
        // PayPal no se acepta en caja
        $this->actingAs($this->vendedor)->postJson($url, ['id_pago' => MetodoPago::idPorCodigo(MetodoPago::PAYPAL), 'clave_idempotencia' => 'pos-http-000000000004'] + $datos)
            ->assertStatus(422)->assertJsonValidationErrors('id_pago');
        $this->assertSame(1, Pago::count());
    }

    public function test_el_cobro_en_caja_exige_permiso(): void
    {
        $r = $this->reservaCobrable('300.00');
        $datos = ['id_pago' => MetodoPago::idPorCodigo(MetodoPago::EFECTIVO), 'monto' => '10', 'efectivo_recibido' => '10', 'clave_idempotencia' => 'pos-http-000000000009'];

        $this->actingAs($this->usuarioConRol('Técnico'))->postJson(route('finanzas.reservas.cobros.store', $r), $datos)->assertForbidden();
        $this->actingAs($this->usuarioConRol('Almacenero'))->postJson(route('finanzas.reservas.cobros.store', $r), $datos)->assertForbidden();
        $this->actingAs(Usuario::find($r->ci_cliente))->postJson(route('finanzas.reservas.cobros.store', $r), $datos)->assertForbidden();
        $this->assertSame(0, Pago::count());
    }

    public function test_estado_de_cuenta_para_el_titular_y_el_personal(): void
    {
        $titular = $this->cliente();
        $r = $this->reservaCobrable('300.00', '0.00', Reserva::ESTADO_CONFIRMADA, $titular);
        $this->cobrar($this->vendedor, $r, '100.00');
        $url = route('finanzas.reservas.estado-cuenta', $r);

        $this->actingAs($titular)->getJson($url)->assertOk()
            ->assertJson(['estado_pago' => 'parcial', 'exigible' => '300.00', 'cobrado' => '100.00', 'saldo' => '200.00', 'moneda' => 'BOB'])
            ->assertJsonCount(1, 'pagos');
        $this->actingAs($this->vendedor)->getJson($url)->assertOk();
        $this->actingAs($this->cliente())->getJson($url)->assertForbidden();
    }
}
