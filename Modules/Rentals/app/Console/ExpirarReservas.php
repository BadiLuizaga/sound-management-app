<?php

namespace Modules\Rentals\Console;

use Illuminate\Console\Command;
use Modules\Rentals\Services\ReservaService;

/**
 * Vence las reservas pendientes cuyo plazo de seguridad pasó (D2).
 * Programada cada minuto en RentalsServiceProvider.
 */
class ExpirarReservas extends Command
{
    protected $signature = 'reservas:expirar';

    protected $description = 'Marca como expiradas las reservas pendientes vencidas';

    public function handle(ReservaService $reservas): int
    {
        $vencidas = $reservas->expirarVencidas();
        $this->info("Reservas vencidas: {$vencidas}");

        return self::SUCCESS;
    }
}
