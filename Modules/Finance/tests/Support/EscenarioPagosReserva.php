<?php

namespace Modules\Finance\Tests\Support;

use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\PagoReservaService;
use Modules\Finance\Support\Dinero;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Tests\Support\EscenarioReservas;

/**
 * Etapa 4 — paso 4: datos ficticios para los pagos de reservas (solo en la base
 * de pruebas). Combina los escenarios de Finanzas y de Reservas.
 */
trait EscenarioPagosReserva
{
    use EscenarioFinanzas;
    use EscenarioReservas;

    /** Reserva confirmada con una unidad bloqueada, lista para cobrar. */
    protected function reservaCobrable(string $total = '300.00', string $cargos = '0.00', string $estado = Reserva::ESTADO_CONFIRMADA, ?Usuario $titular = null): Reserva
    {
        $m = $this->equipo(1);

        return $this->reservaConEquipos([$m->id => 1], '2026-12-10 10:00:00', '2026-12-10 13:00:00', $estado, [
            'total' => $total,
            'total_cargos' => $cargos,
            'ci_cliente' => ($titular ?? $this->cliente())->ci,
            'canal' => Reserva::CANAL_POS,
        ]);
    }

    protected function cobrar(Usuario $cajero, Reserva $r, string $monto, string $metodo = 'EFECTIVO', array $datos = []): Pago
    {
        $datos += ['clave_idempotencia' => 'clave-' . Str::lower(Str::random(24))];
        if ($metodo === 'EFECTIVO') {
            $datos += ['efectivo_recibido' => $monto];
        } else {
            $datos += ['referencia' => 'REF-' . Str::upper(Str::random(10))];
        }

        return app(PagoReservaService::class)->cobrarEnCaja($cajero, $r, $metodo, Dinero::deDecimal($monto, 'BOB'), $datos);
    }

    /** @return array<string, mixed> */
    protected function cuenta(Reserva $r): array
    {
        return app(PagoReservaService::class)->estadoCuenta($r)->toArray();
    }
}
