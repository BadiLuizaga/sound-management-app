<?php

namespace Modules\Finance\Tests\Feature;

use Database\Factories\UsuarioFactory;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\Usuario;
use Modules\Finance\Enums\EstadoPago;
use Modules\Finance\Models\Pago;
use Modules\Finance\Services\PagoReservaService;
use Modules\Rentals\Models\Reserva;
use Modules\Sales\Models\Caja;
use Tests\TestCase;

/**
 * Etapa 4 — paso 4: concurrencia REAL de cobros de reservas en MySQL.
 *
 * Dos procesos PHP independientes, cada uno con su propia conexión y con un
 * cajero y una caja abierta distintos, cobran a la vez la MISMA reserva
 * (tests/Support/concurrencia/cobrar-reserva.php). Una barrera (GET_LOCK) los
 * hace arrancar juntos. El que obtiene primero el bloqueo FOR UPDATE de la
 * reserva lo retiene PAUSA_MS; el otro tiene que esperarlo y, al obtenerlo,
 * ve el saldo real: nunca hay sobrepago ni saldo inconsistente.
 *
 * SQLite no tiene bloqueo de filas: allí se omite con motivo explícito; la
 * secuencia equivalente está en PagoReservaPresencialTest.
 *
 * Los datos se confirman (commit) para que los vean los otros procesos: no se
 * usa la transacción de RefreshDatabase. La prueba NO recrea ni limpia la base:
 * exige el esquema completo y la configuración sembrada, y reutiliza 2 cajeros,
 * 2 cajas y 1 titular para los tres escenarios (1 reserva por escenario).
 */
class ConcurrenciaPagosReservaMySqlTest extends TestCase
{
    /** Regla del proyecto (CLAUDE.md): validación mínima, una repetición por escenario. */
    private const REPETICIONES = 1;
    private const PAUSA_MS = 300;

    /** @var array<int, string> */
    private static array $evidencia = [];

    /**
     * Registros reutilizados por los tres escenarios (máximo 5 por módulo):
     * 2 cajeros con su caja abierta y 1 cliente titular. Se crean una sola vez.
     *
     * @var array<int, Usuario>
     */
    private static array $cajeros = [];
    private static ?Usuario $titular = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrencia real solo en MySQL: SQLite no tiene bloqueo de filas (FOR UPDATE se ignora y la base se serializa entera). Equivalente secuencial en PagoReservaPresencialTest.');
        }
        $this->verificarDestino();

        // No se recrea la base (sin migrate:fresh): se usa el esquema y la
        // configuración existentes y no se borra ningún registro.
        $faltantes = [];
        if ((int) DB::table('migrations')->count() < 52) {
            $faltantes[] = 'esquema incompleto (' . DB::table('migrations')->count() . ' de 52 migraciones)';
        }
        if (!DB::getSchemaBuilder()->hasTable('rol') || !DB::table('rol')->where('nombre', 'Vendedor')->exists()) {
            $faltantes[] = 'falta el rol Vendedor';
        }
        if (!DB::getSchemaBuilder()->hasTable('metodoPago') || !DB::table('metodoPago')->where('codigo', 'EFECTIVO')->where('activo', true)->exists()) {
            $faltantes[] = 'falta el método de pago EFECTIVO activo';
        }
        if ($faltantes !== []) {
            $this->fail('La base de pruebas no está lista y esta prueba no la modifica: ' . implode('; ', $faltantes) . '.');
        }

        if (self::$cajeros === []) {
            foreach ([0, 1] as $n) {
                $cajero = UsuarioFactory::new()->conRol('Vendedor')->create();
                Caja::create(['user_id' => $cajero->ci, 'monto_apertura' => '100.00', 'estado' => 'abierta', 'fecha_apertura' => now(), 'version_arqueo' => 2]);
                self::$cajeros[$n] = $cajero;
            }
            self::$titular = UsuarioFactory::new()->cliente()->create();
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
            file_put_contents($dir . '/e4-paso4-correccion-concurrencia-pagos-tiempos.txt', implode(PHP_EOL, self::$evidencia) . PHP_EOL);
        }
        parent::tearDownAfterClass();
    }

    // ───────────────────────── Escenarios ─────────────────────────

    public function test_dos_cobros_por_el_saldo_completo_solo_entra_uno(): void
    {
        for ($i = 1; $i <= self::REPETICIONES; $i++) {
            $r = $this->reserva('300.00');

            $res = $this->cobrarEnParalelo($r->id, '300.00', '300.00', "saldo completo #{$i}");

            $this->assertEqualsCanonicalizing(['cobrado', 'rechazado'], [$res[0]['resultado'], $res[1]['resultado']], "Iteración {$i}");
            $rechazo = $res[0]['resultado'] === 'rechazado' ? $res[0] : $res[1];
            $this->assertStringContainsString('ya está pagada', (string) $rechazo['mensaje'], "Iteración {$i}");
            $this->assertCuenta($r, '300.00', Reserva::PAGO_PAGADO, 1, $i);
            $this->assertSerializado($res, $i);
        }
    }

    public function test_dos_anticipos_que_caben_entran_los_dos(): void
    {
        for ($i = 1; $i <= self::REPETICIONES; $i++) {
            $r = $this->reserva('300.00');

            $res = $this->cobrarEnParalelo($r->id, '150.00', '150.00', "caben #{$i}");

            $this->assertSame(['cobrado', 'cobrado'], [$res[0]['resultado'], $res[1]['resultado']], "Iteración {$i}");
            $this->assertCuenta($r, '300.00', Reserva::PAGO_PAGADO, 2, $i);
            $this->assertSerializado($res, $i);
        }
    }

    public function test_dos_anticipos_que_no_caben_solo_entra_uno(): void
    {
        for ($i = 1; $i <= self::REPETICIONES; $i++) {
            $r = $this->reserva('300.00');

            $res = $this->cobrarEnParalelo($r->id, '200.00', '200.00', "no caben #{$i}");

            $this->assertEqualsCanonicalizing(['cobrado', 'rechazado'], [$res[0]['resultado'], $res[1]['resultado']], "Iteración {$i}");
            $rechazo = $res[0]['resultado'] === 'rechazado' ? $res[0] : $res[1];
            $this->assertStringContainsString('excede el saldo disponible', (string) $rechazo['mensaje'], "Iteración {$i}");
            $this->assertCuenta($r, '200.00', Reserva::PAGO_PARCIAL, 1, $i);
            $this->assertSerializado($res, $i);
        }
    }

    // ───────────────────────── Ayudantes ─────────────────────────

    /** Reserva confirmada del mismo titular. Sin equipos: el cobro no los necesita. */
    private function reserva(string $total): Reserva
    {
        return Reserva::create([
            'codigo' => 'R-CONC-' . Str::upper(Str::random(8)),
            'ci_cliente' => self::$titular->ci,
            'estado' => Reserva::ESTADO_CONFIRMADA,
            'canal' => Reserva::CANAL_POS,
            'fecha_inicio' => '2026-12-10 10:00:00',
            'fecha_fin' => '2026-12-10 13:00:00',
            'horas' => 3,
            'total' => $total,
        ]);
    }

    private function assertCuenta(Reserva $r, string $cobrado, string $estadoPago, int $pagos, int $i): void
    {
        $cuenta = app(PagoReservaService::class)->estadoCuenta($r)->toArray();
        $this->assertSame($cobrado, $cuenta['cobrado'], "Iteración {$i}: cobrado");
        $this->assertSame('0.00', $cuenta['sobrepago'], "Sobrepago en la iteración {$i}");
        $this->assertSame($estadoPago, $r->fresh()->estado_pago, "Iteración {$i}: estado de pago");
        $this->assertSame($pagos, Pago::where('reserva_id', $r->id)->where('estado', EstadoPago::Recibido->value)->count(), "Iteración {$i}: pagos");
        $this->assertSame($pagos, Pago::where('reserva_id', $r->id)->count(), "Iteración {$i}: ningún pago a medias");
    }

    /** @return array{0: array, 1: array} */
    private function cobrarEnParalelo(int $reservaId, string $montoA, string $montoB, string $etiqueta): array
    {
        $barrera = 'e4p4_barrera_' . Str::lower(Str::random(12));
        $this->assertSame(1, (int) DB::selectOne('SELECT GET_LOCK(?, 10) AS l', [$barrera])->l, 'No se obtuvo la barrera');

        $procesos = [];
        try {
            foreach ([$montoA, $montoB] as $n => $monto) {
                $procesos[$n] = $this->lanzar($reservaId, self::$cajeros[$n], $monto, $barrera, $n);
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
            '%-18s | A=%-9s espera %7.1f ms | B=%-9s espera %7.1f ms | desfase de arranque %5.1f ms',
            $etiqueta,
            $resultados[0]['resultado'], $resultados[0]['espera_bloqueo_ms'],
            $resultados[1]['resultado'], $resultados[1]['espera_bloqueo_ms'],
            abs($resultados[0]['inicio'] - $resultados[1]['inicio']) * 1000
        );

        return $resultados;
    }

    private function lanzar(int $reservaId, Usuario $cajero, string $monto, string $barrera, int $n): array
    {
        $err = storage_path("logs/e4p4-concurrencia-{$barrera}-{$n}.log");
        $comando = [PHP_BINARY];
        if ($ini = php_ini_loaded_file()) {
            array_push($comando, '-c', $ini);
        }
        array_push($comando, dirname(__DIR__) . '/Support/concurrencia/cobrar-reserva.php',
            (string) $reservaId, (string) $cajero->ci, $monto, 'conc-' . Str::lower(Str::random(20)), $barrera, (string) self::PAUSA_MS);

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

    /** El segundo cobro ESPERÓ el bloqueo de la reserva del primero. */
    private function assertSerializado(array $r, int $i): void
    {
        $esperas = [$r[0]['espera_bloqueo_ms'], $r[1]['espera_bloqueo_ms']];
        $this->assertNotNull($esperas[0]);
        $this->assertNotNull($esperas[1]);
        $this->assertGreaterThanOrEqual(self::PAUSA_MS * 0.5, max($esperas), "Iteración {$i}: ningún cobro esperó el bloqueo (" . implode(', ', $esperas) . ' ms)');
    }

    private function errores(array $p): string
    {
        return is_file($p['err']) ? trim((string) file_get_contents($p['err'])) : '';
    }
}
