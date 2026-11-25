<?php

namespace Modules\Rentals\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Tests\Support\EscenarioReservas;
use Tests\TestCase;

/**
 * Etapa 4 — paso 3 (C3): concurrencia REAL en MySQL.
 *
 * Dos procesos PHP independientes, cada uno con su propia conexión, confirman
 * a la vez dos reservas que compiten por las mismas unidades
 * (tests/Support/concurrencia/confirmar-reserva.php). Una barrera (GET_LOCK)
 * los hace arrancar juntos. El que obtiene primero el bloqueo FOR UPDATE de
 * las maquinarias lo retiene PAUSA_MS; el otro tiene que esperarlo.
 *
 * Cada escenario se repite REPETICIONES veces con equipos nuevos.
 *
 * SQLite no tiene bloqueo de filas (serializa la base entera y no admite dos
 * procesos escribiendo en una base en memoria), así que allí se omite con
 * motivo explícito; la secuencia equivalente está en ReservaServiceTest.
 *
 * Los datos deben confirmarse (commit) para que los vean los otros procesos:
 * no se usa la transacción de RefreshDatabase. La clase migra desde cero una
 * vez y obliga a la siguiente clase a migrar de nuevo.
 */
class ConcurrenciaReservasMySqlTest extends TestCase
{
    use EscenarioReservas;

    private const REPETICIONES = 20;
    private const PAUSA_MS = 300;

    private static bool $preparada = false;

    /** @var array<int, string> */
    private static array $evidencia = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrencia real solo en MySQL: SQLite no tiene bloqueo de filas (FOR UPDATE se ignora y la base se serializa entera). Equivalente secuencial en ReservaServiceTest.');
        }
        $this->verificarDestino();

        if (!self::$preparada) {
            Artisan::call('migrate:fresh', ['--seed' => true]);
            self::$preparada = true;
        }
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        $dir = dirname(__DIR__, 4) . '/.tools/resultados';
        if (self::$evidencia !== [] && is_dir($dir)) {
            file_put_contents($dir . '/e4-paso3-concurrencia-tiempos.txt', implode(PHP_EOL, self::$evidencia) . PHP_EOL);
        }
        parent::tearDownAfterClass();
    }

    // ───────────────────────── Escenarios ─────────────────────────

    public function test_unidades_insuficientes_solo_se_confirma_una(): void
    {
        for ($i = 1; $i <= self::REPETICIONES; $i++) {
            $m = $this->equipo(2);
            $p = $this->paquete([$m->id => 2]);
            $a = $this->reservaPendiente($p, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00');
            $b = $this->reservaPendiente($p, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00');

            $r = $this->confirmarEnParalelo($a->id, $b->id, "insuficientes #{$i}");

            $this->assertEqualsCanonicalizing(['confirmada', 'rechazada'], [$r[0]['estado'], $r[1]['estado']], "Iteración {$i}");
            $this->assertUnidadesNoExceden($m->id, 2, $i);
            $this->assertSerializado($r, $i);
            $rechazada = Reserva::whereIn('id', [$a->id, $b->id])->where('estado', 'rechazada')->first();
            $this->assertStringContainsString('se necesitan 2, hay 0', $rechazada->motivo_cancelacion);
            $this->assertSame(0, ReservaEquipo::where('reserva_id', $rechazada->id)->count());
        }
    }

    public function test_unidades_suficientes_se_confirman_las_dos(): void
    {
        for ($i = 1; $i <= self::REPETICIONES; $i++) {
            $m = $this->equipo(4);
            $p = $this->paquete([$m->id => 2]);
            $a = $this->reservaPendiente($p, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00');
            $b = $this->reservaPendiente($p, 1, '2026-12-10 10:00:00', '2026-12-10 14:00:00');

            $r = $this->confirmarEnParalelo($a->id, $b->id, "suficientes #{$i}");

            $this->assertSame(['confirmada', 'confirmada'], [$r[0]['estado'], $r[1]['estado']], "Iteración {$i}");
            $this->assertSame(4, (int) ReservaEquipo::where('maquinaria_id', $m->id)->where('estado', 'reservado')->sum('cantidad'));
            $this->assertSerializado($r, $i);
        }
    }

    public function test_reservas_consecutivas_sin_margen_suficiente_solo_se_confirma_una(): void
    {
        for ($i = 1; $i <= self::REPETICIONES; $i++) {
            $m = $this->equipo(1);
            $p = $this->paquete([$m->id => 1]);
            // 30 minutos entre ambas: menos que el margen de 60 (C1)
            $a = $this->reservaPendiente($p, 1, '2026-12-10 10:00:00', '2026-12-10 12:00:00');
            $b = $this->reservaPendiente($p, 1, '2026-12-10 12:30:00', '2026-12-10 14:30:00');

            $r = $this->confirmarEnParalelo($a->id, $b->id, "margen #{$i}");

            $this->assertEqualsCanonicalizing(['confirmada', 'rechazada'], [$r[0]['estado'], $r[1]['estado']], "Iteración {$i}");
            $this->assertUnidadesNoExceden($m->id, 1, $i);
            $this->assertSerializado($r, $i);
        }
    }

    // ───────────────────────── Ayudantes ─────────────────────────

    /** @return array{0: array, 1: array} resultado JSON de cada proceso */
    private function confirmarEnParalelo(int $reservaA, int $reservaB, string $etiqueta): array
    {
        $barrera = 'e4_barrera_' . Str::lower(Str::random(12));
        $this->assertSame(1, (int) DB::selectOne('SELECT GET_LOCK(?, 10) AS l', [$barrera])->l, 'No se obtuvo la barrera');

        $procesos = [];
        try {
            foreach ([$reservaA, $reservaB] as $n => $reservaId) {
                $procesos[$n] = $this->lanzar($reservaId, $barrera, $n);
            }
            foreach ($procesos as $n => $p) {
                $linea = fgets($p['out']);
                $this->assertSame("LISTO\n", str_replace("\r", '', (string) $linea), "El proceso {$n} no arrancó: " . $this->errores($p));
            }
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [$barrera]); // ¡ya!
        }

        $resultados = [];
        foreach ($procesos as $n => $p) {
            $json = stream_get_contents($p['out']);
            fclose($p['out']);
            $codigo = proc_close($p['proc']);
            $datos = json_decode(trim((string) $json), true);
            $this->assertIsArray($datos, "Salida inválida del proceso {$n}: {$json} " . $this->errores($p));
            $this->assertTrue($datos['ok'] ?? false, "El proceso {$n} falló: " . ($datos['error'] ?? '') . ' ' . $this->errores($p));
            $this->assertSame(0, $codigo, "Código de salida del proceso {$n}");
            @unlink($p['err']);
            $resultados[$n] = $datos;
        }

        self::$evidencia[] = sprintf(
            '%-16s | A=%-10s espera %7.1f ms | B=%-10s espera %7.1f ms | desfase de arranque %5.1f ms',
            $etiqueta,
            $resultados[0]['estado'], $resultados[0]['espera_bloqueo_ms'],
            $resultados[1]['estado'], $resultados[1]['espera_bloqueo_ms'],
            abs($resultados[0]['inicio'] - $resultados[1]['inicio']) * 1000
        );

        return $resultados;
    }

    private function lanzar(int $reservaId, string $barrera, int $n): array
    {
        $err = storage_path("logs/e4-concurrencia-{$barrera}-{$n}.log");
        $comando = [PHP_BINARY];
        if ($ini = php_ini_loaded_file()) {
            array_push($comando, '-c', $ini);
        }
        array_push($comando, dirname(__DIR__) . '/Support/concurrencia/confirmar-reserva.php', (string) $reservaId, $barrera, (string) self::PAUSA_MS);

        $proc = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['file', $err, 'w']], $pipes, base_path(), $this->entornoHijo());
        $this->assertIsResource($proc, 'No se pudo lanzar el proceso hijo');

        return ['proc' => $proc, 'out' => $pipes[1], 'err' => $err];
    }

    /** Entorno del proceso hijo: el actual, con la conexión de pruebas explícita. */
    private function entornoHijo(): array
    {
        $c = config('database.connections.mysql');
        $env = getenv();
        foreach (['DB_URL', 'DATABASE_URL'] as $k) {
            unset($env[$k]);
        }

        return array_merge($env, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $c['host'],
            'DB_PORT' => (string) $c['port'],
            'DB_DATABASE' => (string) $c['database'],
            'DB_USERNAME' => (string) $c['username'],
            'DB_PASSWORD' => (string) $c['password'],
        ]);
    }

    private function verificarDestino(): void
    {
        $d = DB::selectOne('SELECT DATABASE() AS base, @@port AS puerto, CURRENT_USER() AS usuario');
        if ($d->base !== 'sound_management_test' || (int) $d->puerto !== 3310 || !str_starts_with($d->usuario, 'sound_test@')) {
            $this->fail('ABORTADO: la conexión no es sound_test@127.0.0.1:3310/sound_management_test');
        }
    }

    private function assertUnidadesNoExceden(int $maquinariaId, int $total, int $i): void
    {
        $reservadas = (int) ReservaEquipo::where('maquinaria_id', $maquinariaId)->where('estado', 'reservado')->sum('cantidad');
        $this->assertLessThanOrEqual($total, $reservadas, "Sobreventa en la iteración {$i}");
        $this->assertSame($total, $reservadas, "Una de las dos debía quedarse con las unidades (iteración {$i})");
    }

    /** La segunda confirmación ESPERÓ el bloqueo de la primera. */
    private function assertSerializado(array $r, int $i): void
    {
        $esperas = [$r[0]['espera_bloqueo_ms'], $r[1]['espera_bloqueo_ms']];
        $this->assertNotNull($esperas[0]);
        $this->assertNotNull($esperas[1]);
        $this->assertGreaterThanOrEqual(self::PAUSA_MS * 0.5, max($esperas), "Iteración {$i}: ninguna confirmación esperó el bloqueo (" . implode(', ', $esperas) . ' ms)');
    }

    private function errores(array $p): string
    {
        return is_file($p['err']) ? trim((string) file_get_contents($p['err'])) : '';
    }
}
