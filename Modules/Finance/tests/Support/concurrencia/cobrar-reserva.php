<?php

/**
 * Etapa 4 — paso 4: proceso PHP INDEPENDIENTE (con su propia conexión MySQL)
 * que cobra en caja una reserva. Lo lanza ConcurrenciaPagosReservaMySqlTest,
 * dos a la vez, cada uno con un cajero y una caja abierta distintos.
 *
 * Uso: php cobrar-reserva.php <reserva_id> <ci_cajero> <monto> <clave> <barrera> <pausa_ms>
 *
 *  1. Arranca la aplicación y VERIFICA el destino: solo
 *     sound_test@127.0.0.1:3310/sound_management_test. Si no coincide, sale (3).
 *  2. Escribe "LISTO" y espera la barrera (GET_LOCK retenido por la prueba).
 *  3. Llama a PagoReservaService::cobrarEnCaja. Un decorador de AplicadorReserva
 *     mide cuánto tardó en obtener el bloqueo FOR UPDATE de la reserva y, ya con
 *     el bloqueo, espera <pausa_ms>: así el otro proceso tiene que esperarlo.
 *  4. Escribe una línea JSON: 'cobrado' o 'rechazado' (regla de negocio). Un
 *     error inesperado (p. ej., un interbloqueo no resuelto) es ok=false.
 *
 * Solo para pruebas; no forma parte de la aplicación.
 */

use Illuminate\Support\Facades\DB;
use Modules\Access\Models\Usuario;
use Modules\Finance\Aplicadores\AplicadorReserva;
use Modules\Finance\Services\PagoReservaService;
use Modules\Finance\Support\Dinero;
use Modules\Rentals\Models\Reserva;

[, $reservaId, $ciCajero, $monto, $clave, $barrera, $pausaMs] = $argv + [null, null, null, null, null, null, '0'];
if (!ctype_digit((string) $reservaId) || !ctype_digit((string) $ciCajero) || !preg_match('/^\d+(\.\d{1,2})?$/', (string) $monto)
    || !preg_match('/^[A-Za-z0-9-]{16,50}$/', (string) $clave) || !preg_match('/^[A-Za-z0-9_]{1,60}$/', (string) $barrera)) {
    fwrite(STDERR, "Argumentos inválidos\n");
    exit(2);
}
set_time_limit(90);

$raiz = dirname(__DIR__, 5);
require $raiz . '/vendor/autoload.php';
$app = require $raiz . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Destino de pruebas, por configuración y por la propia conexión
$nombre = config('database.default');
$c = config("database.connections.{$nombre}");
if ($nombre !== 'mysql' || ($c['host'] ?? null) !== '127.0.0.1' || (string) ($c['port'] ?? '') !== '3310'
    || ($c['database'] ?? null) !== 'sound_management_test' || ($c['username'] ?? null) !== 'sound_test') {
    fwrite(STDERR, "ABORTADO: el destino no es sound_test@127.0.0.1:3310/sound_management_test\n");
    exit(3);
}
$destino = DB::selectOne('SELECT DATABASE() AS base, @@port AS puerto, CURRENT_USER() AS usuario');
if ($destino->base !== 'sound_management_test' || (int) $destino->puerto !== 3310 || !str_starts_with($destino->usuario, 'sound_test@')) {
    fwrite(STDERR, "ABORTADO: la conexión no es la base de pruebas\n");
    exit(3);
}

final class AplicadorReservaConPausa extends AplicadorReserva
{
    public ?float $esperaBloqueoMs = null;

    public function __construct(private readonly int $pausaMs)
    {
    }

    public function bloquear(int $reservaId): Reserva
    {
        $t0 = hrtime(true);
        $reserva = parent::bloquear($reservaId);
        if ($this->esperaBloqueoMs === null) {
            $this->esperaBloqueoMs = (hrtime(true) - $t0) / 1e6;
            usleep($this->pausaMs * 1000); // con la reserva bloqueada
        }

        return $reserva;
    }
}

$aplicador = new AplicadorReservaConPausa(max(0, (int) $pausaMs));
$app->instance(AplicadorReserva::class, $aplicador);

$cajero = Usuario::findOrFail((int) $ciCajero);

// 2. Barrera de inicio
fwrite(STDOUT, "LISTO\n");
fflush(STDOUT);
$limite = microtime(true) + 30;
while ((int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS libre', [$barrera])->libre !== 1) {
    if (microtime(true) > $limite) {
        fwrite(STDERR, "La barrera no se liberó a tiempo\n");
        exit(4);
    }
    usleep(1000);
}

// 3. Cobro
$inicio = microtime(true);
try {
    $pago = app(PagoReservaService::class)->cobrarEnCaja($cajero, (int) $reservaId, 'EFECTIVO', Dinero::deDecimal((string) $monto, 'BOB'), [
        'clave_idempotencia' => (string) $clave,
        'efectivo_recibido' => (string) $monto,
    ]);
    $salida = ['ok' => true, 'resultado' => 'cobrado', 'pago' => $pago->uuid, 'mensaje' => null];
    $codigo = 0;
} catch (DomainException $e) {
    $salida = ['ok' => true, 'resultado' => 'rechazado', 'pago' => null, 'mensaje' => $e->getMessage()];
    $codigo = 0;
} catch (Throwable $e) {
    $salida = ['ok' => false, 'resultado' => 'error', 'error' => get_class($e) . ': ' . $e->getMessage()];
    $codigo = 1;
}

// 4. Resultado
fwrite(STDOUT, json_encode($salida + [
    'espera_bloqueo_ms' => $aplicador->esperaBloqueoMs,
    'inicio' => $inicio,
    'fin' => microtime(true),
]) . "\n");
fflush(STDOUT);
exit($codigo);
