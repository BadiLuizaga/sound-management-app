<?php

/**
 * Etapa 4 — paso 3: proceso PHP INDEPENDIENTE (con su propia conexión MySQL)
 * que confirma una reserva. Lo lanza ConcurrenciaReservasMySqlTest, dos a la vez.
 *
 * Uso: php confirmar-reserva.php <reserva_id> <barrera> <pausa_ms>
 *
 *  1. Arranca la aplicación y VERIFICA el destino: solo
 *     sound_test@127.0.0.1:3310/sound_management_test. Si no coincide, sale (3).
 *  2. Escribe "LISTO" y espera la barrera: un GET_LOCK que retiene la prueba.
 *     Al liberarse, los dos procesos arrancan a la vez (sondean IS_FREE_LOCK,
 *     no lo toman, así no se serializan entre sí).
 *  3. Llama a ReservaService::confirmar. Un decorador de DisponibilidadService
 *     mide cuánto tardó en obtener el bloqueo FOR UPDATE y, ya con el bloqueo,
 *     espera <pausa_ms> antes de seguir: así el otro proceso tiene que esperar.
 *  4. Escribe una línea JSON con el resultado.
 *
 * Solo para pruebas; no forma parte de la aplicación.
 */

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Rentals\Services\DisponibilidadService;
use Modules\Rentals\Services\ReservaService;
use Modules\Rentals\Support\ResultadoDisponibilidad;

[, $reservaId, $barrera, $pausaMs] = $argv + [null, null, null, '0'];
if (!ctype_digit((string) $reservaId) || !is_string($barrera) || !preg_match('/^[A-Za-z0-9_]{1,60}$/', $barrera)) {
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

final class DisponibilidadConPausa extends DisponibilidadService
{
    public ?float $esperaBloqueoMs = null;

    public function __construct(private readonly int $pausaMs)
    {
    }

    public function consultar(array $solicitadas, CarbonInterface|string $inicio, CarbonInterface|string $fin, ?int $excluirReservaId = null, bool $bloquear = false): ResultadoDisponibilidad
    {
        $t0 = hrtime(true);
        $resultado = parent::consultar($solicitadas, $inicio, $fin, $excluirReservaId, $bloquear);
        if ($bloquear) {
            $this->esperaBloqueoMs = (hrtime(true) - $t0) / 1e6;
            usleep($this->pausaMs * 1000); // con las maquinarias bloqueadas
        }

        return $resultado;
    }
}

$disponibilidad = new DisponibilidadConPausa(max(0, (int) $pausaMs));
$app->instance(DisponibilidadService::class, $disponibilidad);

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

// 3. Confirmación
$inicio = microtime(true);
try {
    $reserva = app(ReservaService::class)->confirmar((int) $reservaId);
    $salida = [
        'ok' => true,
        'reserva' => (int) $reserva->id,
        'estado' => $reserva->estado,
        'espera_bloqueo_ms' => $disponibilidad->esperaBloqueoMs,
        'inicio' => $inicio,
        'fin' => microtime(true),
    ];
    $codigo = 0;
} catch (Throwable $e) {
    $salida = ['ok' => false, 'reserva' => (int) $reservaId, 'error' => get_class($e) . ': ' . $e->getMessage()];
    $codigo = 1;
}

// 4. Resultado
fwrite(STDOUT, json_encode($salida) . "\n");
fflush(STDOUT);
exit($codigo);
