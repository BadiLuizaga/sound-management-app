<?php

namespace Modules\Finance\Console;

use Illuminate\Console\Command;
use Modules\Finance\Services\ConciliacionService;

class ConciliarPagos extends Command
{
    protected $signature = 'finanzas:conciliar';

    protected $description = 'Concilia los pagos en línea con PayPal: expira intentos vencidos (nunca los inciertos), resuelve verificaciones, reaplica pagos, consulta reembolsos del historial y reprocesa webhooks. Nunca ejecuta reembolsos.';

    public function handle(ConciliacionService $conciliacion): int
    {
        $resumen = $conciliacion->ejecutar();

        foreach ($resumen as $clave => $valor) {
            $this->line(str_pad($clave, 24) . $valor);
        }

        return self::SUCCESS;
    }
}
