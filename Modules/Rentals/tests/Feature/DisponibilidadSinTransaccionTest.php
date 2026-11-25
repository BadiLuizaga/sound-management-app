<?php

namespace Modules\Rentals\Tests\Feature;

use LogicException;
use Modules\Rentals\Services\DisponibilidadService;
use Tests\TestCase;

/**
 * Etapa 4 — paso 2: bloquear la disponibilidad (FOR UPDATE) exige una
 * transacción abierta. La comprobación ocurre ANTES de cualquier consulta, así
 * que esta prueba no usa RefreshDatabase (cuya transacción la ocultaría) ni
 * toca la base de datos.
 */
class DisponibilidadSinTransaccionTest extends TestCase
{
    public function test_bloquear_sin_transaccion_se_rechaza(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transacción abierta');

        app(DisponibilidadService::class)->consultar([1 => 1], '2026-12-01 10:00', '2026-12-01 12:00', bloquear: true);
    }
}
