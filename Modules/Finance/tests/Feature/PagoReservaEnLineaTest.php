<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Models\Usuario;
use Modules\Finance\Enums\EstadoAplicacion;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\Pago;
use Modules\Finance\Models\WebhookEvento;
use Modules\Finance\Services\ConciliacionService;
use Modules\Finance\Services\PagoService;
use Modules\Finance\Tests\Support\EscenarioPagosReserva;
use Modules\Finance\Tests\Support\PayPalFalso;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Models\ReservaEvento;
use Modules\Rentals\Services\ReservaService;
use Modules\Sales\Models\FacturaVenta;
use Tests\TestCase;

/**
 * Etapa 4 — paso 4: anticipos y saldos de reservas EN LÍNEA (PayPal), reutilizando
 * PagoService (orden y captura en el servidor), los webhooks verificados, la
 * idempotencia y la conciliación. PayPal simulado: nunca hay red ni cobros reales.
 *
 * Reserva de prueba: Bs. 696.00; tasa 6.96 → USD 100.00.
 */
class PagoReservaEnLineaTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioPagosReserva;

    private const CABECERAS = [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-FICTICIO',
        'PAYPAL-TRANSMISSION-ID' => 'TX-1',
        'PAYPAL-TRANSMISSION-SIG' => 'firma-ficticia',
        'PAYPAL-TRANSMISSION-TIME' => '2026-11-20T12:00:00Z',
    ];

    private PayPalFalso $paypal;
    private Usuario $titular;
    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->paypal = PayPalFalso::instalar();
        $this->tasaHoy('6.96');
        $this->titular = $this->cliente();
        $this->reserva = $this->reservaCobrable('696.00', '0.00', Reserva::ESTADO_CONFIRMADA, $this->titular);
    }

    private function iniciar(?string $monto = null, string $clave = 'web-reserva-00000000001', ?Usuario $quien = null)
    {
        return $this->actingAs($quien ?? $this->titular)->postJson(
            route('finanzas.reservas.paypal.orden', $this->reserva),
            array_filter(['monto' => $monto, 'clave_idempotencia' => $clave])
        );
    }

    private function capturar(string $uuid)
    {
        return $this->actingAs($this->titular)->postJson(url("/pagos/{$uuid}/captura"));
    }

    private function pago(string $uuid): Pago
    {
        return Pago::where('uuid', $uuid)->firstOrFail();
    }

    private function webhook(array $evento)
    {
        auth()->logout();

        return $this->withHeaders(self::CABECERAS)->postJson('/webhooks/paypal', $evento);
    }

    private function eventoCaptura(Pago $p, string $id, string $tipo = 'PAYMENT.CAPTURE.COMPLETED'): array
    {
        return [
            'id' => $id,
            'event_type' => $tipo,
            'resource' => [
                'id' => 'CAP-' . $p->referencia_externa,
                'status' => 'COMPLETED',
                'custom_id' => $p->uuid,
                'amount' => ['currency_code' => 'USD', 'value' => (string) $p->monto_proveedor],
                'supplementary_data' => ['related_ids' => ['order_id' => $p->referencia_externa]],
            ],
        ];
    }

    private function eventoOrdenAprobada(Pago $p, string $id): array
    {
        return [
            'id' => $id,
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => $p->referencia_externa, 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => $p->uuid]]],
        ];
    }

    // ───────────────────────── Anticipo y saldo ─────────────────────────

    public function test_anticipo_en_linea_crea_la_orden_y_la_captura_lo_aplica(): void
    {
        $res = $this->iniciar('348.00')->assertOk()
            ->assertJson(['monto_bob' => '348.00', 'monto_proveedor' => '50.00', 'moneda_proveedor' => 'USD', 'tipo_cobro' => 'anticipo']);
        $pago = $this->pago($res->json('uuid'));

        $this->assertSame(EstadoPago::OrdenCreada, $pago->estado);
        $this->assertSame((int) $this->reserva->id, (int) $pago->reserva_id);
        $this->assertSame(PagoService::TIPO_RESERVA, $pago->snapshot['tipo']);
        $this->assertNull($pago->caja_id, 'Un pago en línea no entra en ninguna caja');
        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago, 'Crear la orden no cuenta como pago');

        $this->capturar($pago->uuid)->assertOk()
            ->assertJson(['estado' => 'recibido', 'estado_aplicacion' => 'aplicado', 'mensaje' => 'Pago recibido y registrado en tu reserva.']);

        $r = $this->reserva->fresh();
        $this->assertSame(Reserva::PAGO_PARCIAL, $r->estado_pago);
        $this->assertSame(Reserva::ESTADO_CONFIRMADA, $r->estado);
        $this->assertSame(1, ReservaEquipo::where('reserva_id', $r->id)->count(), 'Pagar no bloquea equipos');
        $this->assertSame(0, FacturaVenta::count());
        $this->assertSame('348.00', $this->cuenta($r)['saldo']);
        $this->assertSame(1, ReservaEvento::where('reserva_id', $r->id)->where('tipo', ReservaEvento::PAGO_APLICADO)->count());
    }

    public function test_sin_monto_se_paga_todo_el_saldo_disponible(): void
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $this->abrirCaja($vendedor);
        $this->cobrar($vendedor, $this->reserva, '196.00');

        $res = $this->iniciar()->assertOk()->assertJson(['monto_bob' => '500.00', 'tipo_cobro' => 'saldo']);
        $this->capturar($res->json('uuid'))->assertOk();

        $this->assertSame(Reserva::PAGO_PAGADO, $this->reserva->fresh()->estado_pago);
        $this->assertSame('696.00', $this->cuenta($this->reserva)['cobrado']);
    }

    public function test_monto_mayor_al_saldo_rechazado_sin_llamar_a_paypal(): void
    {
        $this->iniciar('696.01')->assertStatus(422)->assertJsonFragment(['message' => 'El importe de 696.01 BOB excede el saldo disponible de la reserva ' . $this->reserva->codigo . ': 696.00 BOB.']);

        $this->assertSame(0, Pago::count());
        $this->assertSame([], $this->paypal->ordenes);
    }

    public function test_reserva_no_cobrable_rechazada_sin_crear_el_intento(): void
    {
        app(ReservaService::class)->cancelar($this->reserva, $this->encargado(), 'Prueba');

        $this->iniciar('100.00')->assertStatus(422)->assertJsonFragment(['message' => "La reserva {$this->reserva->codigo} está «cancelada»: no admite cobros."]);
        $this->assertSame(0, Pago::count());
    }

    public function test_solo_el_titular_paga_en_linea(): void
    {
        $this->iniciar('100.00', 'web-reserva-ajena-000001', $this->cliente())->assertForbidden();
        $this->iniciar('100.00', 'web-reserva-ajena-000002', $this->usuarioConRol('Vendedor'))->assertForbidden();
        $this->assertSame(0, Pago::count());

        // Tampoco puede capturar el pago de otro
        $uuid = $this->iniciar('100.00')->json('uuid');
        $this->actingAs($this->cliente())->postJson(url("/pagos/{$uuid}/captura"))->assertForbidden();
    }

    public function test_misma_clave_devuelve_el_mismo_intento(): void
    {
        $a = $this->iniciar('100.00')->json('uuid');
        $b = $this->iniciar('100.00')->json('uuid');

        $this->assertSame($a, $b);
        $this->assertSame(1, Pago::count());
        $this->assertCount(1, $this->paypal->ordenes);
    }

    public function test_clave_o_monto_invalidos(): void
    {
        $this->iniciar('100.00', 'corta')->assertStatus(422)->assertJsonValidationErrors('clave_idempotencia');
        $this->iniciar('1.234')->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->iniciar('0.00')->assertStatus(422);
        $this->assertSame(0, Pago::count());
    }

    // ───────────────────────── Sobrepago entre intentos ─────────────────────────

    public function test_dos_intentos_por_el_saldo_solo_se_captura_uno(): void
    {
        $a = $this->iniciar(null, 'web-reserva-intento-0000A')->json('uuid');
        $b = $this->iniciar(null, 'web-reserva-intento-0000B')->json('uuid');
        $this->assertSame(EstadoPago::OrdenCreada, $this->pago($b)->estado, 'Una orden sin capturar no compromete el saldo');

        $this->capturar($a)->assertOk()->assertJson(['estado_aplicacion' => 'aplicado']);
        $this->capturar($b)->assertOk()->assertJson(['estado' => 'cancelado']);

        $this->assertSame(1, $this->paypal->capturasSolicitadas, 'La segunda orden nunca se capturó: no hay cobro que devolver');
        $this->assertSame(EstadoAplicacion::NoAplica, $this->pago($b)->estado_aplicacion);
        $this->assertStringContainsString('ya está pagada', $this->pago($b)->motivo_rechazo);
        $this->assertSame(Reserva::PAGO_PAGADO, $this->reserva->fresh()->estado_pago);
        $this->assertSame('0.00', $this->cuenta($this->reserva)['sobrepago']);
    }

    public function test_reserva_cancelada_antes_de_capturar_no_se_cobra(): void
    {
        $uuid = $this->iniciar('100.00')->json('uuid');
        app(ReservaService::class)->cancelar($this->reserva, $this->encargado(), 'El cliente desiste');

        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'cancelado']);

        $this->assertSame(0, $this->paypal->capturasSolicitadas);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago);
    }

    public function test_un_cobro_en_caja_no_se_acepta_mientras_paypal_verifica(): void
    {
        $vendedor = $this->usuarioConRol('Vendedor');
        $this->abrirCaja($vendedor);
        $this->paypal->modoCaptura = 'incierto_500';
        $uuid = $this->iniciar()->json('uuid');

        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'verificando']);
        $this->assertSame('696.00', $this->cuenta($this->reserva)['comprometido']);
        $this->assertThrows(fn () => $this->cobrar($vendedor, $this->reserva, '1.00'), \DomainException::class, 'pendientes de verificación');

        $this->paypal->estadoConsulta = 'COMPLETED';
        app(ConciliacionService::class)->ejecutar();

        $this->assertSame(EstadoAplicacion::Aplicado, $this->pago($uuid)->estado_aplicacion);
        $this->assertSame(Reserva::PAGO_PAGADO, $this->reserva->fresh()->estado_pago);
        $this->assertSame(1, Pago::count());
    }

    // ───────────────────────── Rechazados y en revisión ─────────────────────────

    public function test_captura_rechazada_no_cambia_el_estado_de_pago(): void
    {
        $this->paypal->modoCaptura = 'rechazada';
        $uuid = $this->iniciar()->json('uuid');

        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'fallido']);

        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago);
        $this->assertSame('696.00', $this->cuenta($this->reserva)['disponible']);
    }

    public function test_captura_con_monto_distinto_queda_en_revision_y_se_cierra_manualmente(): void
    {
        $this->paypal->modoCaptura = 'monto_distinto';
        $uuid = $this->iniciar()->json('uuid');

        $this->capturar($uuid)->assertOk()->assertJson(['estado' => 'recibido', 'estado_aplicacion' => 'requiere_revision']);
        $pago = $this->pago($uuid);
        $this->assertStringContainsString('monto o moneda distintos', $pago->motivo_revision);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago, 'Un pago en revisión no cuenta');
        $this->assertSame('0.00', $this->cuenta($this->reserva)['disponible'], 'pero compromete el saldo');

        $this->actingAs($this->admin)->post(route('finanzas.admin.pagos.aplicar-manual', $pago), ['motivo' => 'Diferencia de un centavo verificada'])
            ->assertSessionHas('success');

        $this->assertSame(EstadoAplicacion::AplicadoManual, $pago->fresh()->estado_aplicacion);
        $this->assertSame(Reserva::PAGO_PAGADO, $this->reserva->fresh()->estado_pago);
    }

    public function test_un_intento_vencido_no_afecta_el_saldo(): void
    {
        $uuid = $this->iniciar()->json('uuid');
        Pago::query()->update(['expira_at' => now()->subMinute()]);
        $this->paypal->estadoConsulta = 'CREATED';

        app(ConciliacionService::class)->ejecutar();

        $this->assertSame(EstadoPago::Expirado, $this->pago($uuid)->estado);
        $this->assertSame('696.00', $this->cuenta($this->reserva)['disponible']);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago);
    }

    // ───────────────────────── Webhooks ─────────────────────────

    public function test_webhook_de_captura_aplica_una_sola_vez_aunque_se_repita(): void
    {
        $this->paypal->modoCaptura = 'incierto_500';
        $uuid = $this->iniciar()->json('uuid');
        $this->capturar($uuid);
        $pago = $this->pago($uuid);
        $this->assertSame(EstadoPago::Verificando, $pago->estado);

        $this->webhook($this->eventoCaptura($pago, 'WH-RES-1'))->assertOk();
        $this->assertSame(EstadoAplicacion::Aplicado, $pago->fresh()->estado_aplicacion);
        $this->assertSame(Reserva::PAGO_PAGADO, $this->reserva->fresh()->estado_pago);

        // Mismo evento reenviado por PayPal
        $this->webhook($this->eventoCaptura($pago, 'WH-RES-1'))->assertOk()->assertJson(['message' => 'Evento ya procesado.']);
        // Otro evento (id distinto) por la misma captura
        $this->webhook($this->eventoCaptura($pago, 'WH-RES-2'))->assertOk();
        // La conciliación tampoco la duplica
        app(ConciliacionService::class)->ejecutar();

        $this->assertSame(1, Pago::count());
        $this->assertSame(2, WebhookEvento::count());
        $this->assertSame(1, $pago->eventos()->where('tipo', 'aplicado')->count());
        $this->assertSame(1, ReservaEvento::where('tipo', ReservaEvento::PAGO_APLICADO)->count());
        $this->assertSame('696.00', $this->cuenta($this->reserva)['cobrado']);
        $this->assertSame('0.00', $this->cuenta($this->reserva)['sobrepago']);
    }

    public function test_webhook_de_orden_aprobada_y_captura_completada_no_duplican(): void
    {
        $pago = $this->pago($this->iniciar('348.00')->json('uuid'));

        // El navegador se cerró: la orden aprobada la captura el servidor
        $this->webhook($this->eventoOrdenAprobada($pago, 'WH-APR-1'))->assertOk();
        $this->assertSame(1, $this->paypal->capturasSolicitadas);
        $this->assertSame(EstadoAplicacion::Aplicado, $pago->fresh()->estado_aplicacion);

        $this->webhook($this->eventoCaptura($pago->fresh(), 'WH-CAP-1'))->assertOk();
        $this->webhook($this->eventoOrdenAprobada($pago, 'WH-APR-2'))->assertOk();
        $this->capturar($pago->uuid)->assertOk()->assertJson(['estado' => 'recibido']);

        $this->assertSame(1, $this->paypal->capturasSolicitadas);
        $this->assertSame(Reserva::PAGO_PARCIAL, $this->reserva->fresh()->estado_pago);
        $this->assertSame('348.00', $this->cuenta($this->reserva)['cobrado']);
    }

    public function test_webhook_de_orden_aprobada_de_una_reserva_ya_pagada_no_captura(): void
    {
        $a = $this->iniciar(null, 'web-reserva-intento-0000A')->json('uuid');
        $b = $this->pago($this->iniciar(null, 'web-reserva-intento-0000B')->json('uuid'));
        $this->capturar($a);

        $this->webhook($this->eventoOrdenAprobada($b, 'WH-APR-B'))->assertOk();

        $this->assertSame(1, $this->paypal->capturasSolicitadas);
        $this->assertSame(EstadoPago::Cancelado, $b->fresh()->estado);
        $this->assertSame('696.00', $this->cuenta($this->reserva)['cobrado']);
    }

    public function test_webhook_de_captura_que_causaria_sobrepago_queda_en_revision(): void
    {
        $this->paypal->modoCaptura = 'incierto_500';
        $uuid = $this->iniciar()->json('uuid');
        $this->capturar($uuid);
        // Corrección del total mientras PayPal confirmaba el cobro
        $this->reserva->forceFill(['total' => '300.00'])->save();

        $this->webhook($this->eventoCaptura($this->pago($uuid), 'WH-SOBRE-1'))->assertOk();

        $pago = $this->pago($uuid);
        $this->assertSame(EstadoPago::Recibido, $pago->estado, 'El cobro se conserva');
        $this->assertSame(EstadoAplicacion::RequiereRevision, $pago->estado_aplicacion);
        $this->assertStringContainsString('Sobrepago', $pago->motivo_revision);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago);
    }

    public function test_webhook_de_reverso_recalcula_el_estado_de_pago(): void
    {
        $uuid = $this->iniciar()->json('uuid');
        $this->capturar($uuid);
        $this->assertSame(Reserva::PAGO_PAGADO, $this->reserva->fresh()->estado_pago);
        $pago = $this->pago($uuid);

        $this->webhook([
            'id' => 'WH-REV-1',
            'event_type' => 'PAYMENT.CAPTURE.REVERSED',
            'resource' => ['id' => $pago->referencia_captura, 'status' => 'REVERSED'],
        ])->assertOk();

        $this->assertSame(EstadoPago::Revertido, $pago->fresh()->estado);
        $this->assertSame(EstadoAplicacion::Aplicado, $pago->fresh()->estado_aplicacion, 'La aplicación ya cerrada no se reabre');
        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago);
        $this->assertSame('696.00', $this->cuenta($this->reserva)['saldo']);
        $this->assertSame(1, ReservaEvento::where('tipo', ReservaEvento::ESTADO_PAGO_ACTUALIZADO)->count());
    }

    public function test_webhook_con_firma_invalida_no_aplica_nada(): void
    {
        $this->paypal->modoCaptura = 'incierto_500';
        $uuid = $this->iniciar()->json('uuid');
        $this->capturar($uuid);
        $this->paypal->firmaValida = false;

        $this->webhook($this->eventoCaptura($this->pago($uuid), 'WH-FALSO-1'))->assertStatus(400);

        $this->assertSame(EstadoPago::Verificando, $this->pago($uuid)->estado);
        $this->assertSame(Reserva::PAGO_PENDIENTE, $this->reserva->fresh()->estado_pago);
    }
}
