<?php

namespace Modules\Rentals\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Rentals\Exceptions\CotizacionInvalida;
use Modules\Rentals\Models\Reserva;
use Modules\Rentals\Models\ReservaEquipo;
use Modules\Rentals\Services\CotizadorReserva;
use Modules\Rentals\Tests\Support\EscenarioReservas;
use Tests\TestCase;

/**
 * Etapa 4 — paso 2: cotización solo por paquetes y horas completas, con el
 * precio congelado y el cálculo exacto en centavos.
 */
class CotizadorReservaTest extends TestCase
{
    use RefreshDatabase;
    use EscenarioReservas;

    private CotizadorReserva $cotizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->cotizador = app(CotizadorReserva::class);
    }

    private function rechaza(callable $accion, string $mensaje): void
    {
        try {
            $accion();
            $this->fail("Se esperaba CotizacionInvalida: {$mensaje}");
        } catch (CotizacionInvalida $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }

    public function test_total_exacto_por_paquetes_horas_y_cantidad(): void
    {
        $parlante = $this->equipo(10);
        $consola = $this->equipo(2);
        $basico = $this->paquete([$parlante->id => 2, $consola->id => 1], '33.33');
        $extra = $this->paquete([$parlante->id => 1], '100.00');

        $c = $this->cotizador->cotizar([
            ['paquete_id' => $basico->id, 'cantidad' => 3],
            ['paquete_id' => (string) $extra->id, 'cantidad' => '1'],
        ], '2026-12-01 10:00', '2026-12-01 13:00');

        $this->assertSame(3, $c->horas);
        $this->assertSame('299.97', $c->lineas[$basico->id]['subtotal']); // 33.33 × 3 h × 3
        $this->assertSame('300.00', $c->lineas[$extra->id]['subtotal']);  // 100 × 3 h × 1
        $this->assertSame('599.97', $c->total->decimal());
        $this->assertSame(59997, $c->total->centavos);
        $this->assertSame('BOB', $c->total->moneda);
        // Unidades por equipo: 2×3 + 1×1 parlantes, 1×3 consolas
        $this->assertSame([$parlante->id => 7, $consola->id => 3], $c->requerimientos);
    }

    public function test_solo_paquetes_y_horas_sin_tarifa_por_dia(): void
    {
        $m = $this->equipo(1); // precio_dia = 300: no se usa
        $p = $this->paquete([$m->id => 1], '10.00');

        $c = $this->cotizador->cotizar([['paquete_id' => $p->id]], '2026-12-01 08:00', '2026-12-02 08:00');

        $this->assertSame(24, $c->horas);
        $this->assertSame('240.00', $c->total->decimal());
        $this->assertSame(1, $c->lineas[$p->id]['cantidad'], 'Sin cantidad, se cotiza 1');
    }

    public function test_solo_horas_completas(): void
    {
        $p = $this->paquete([$this->equipo()->id => 1]);
        $linea = [['paquete_id' => $p->id]];

        $this->rechaza(fn () => $this->cotizador->cotizar($linea, '2026-12-01 10:00', '2026-12-01 11:30'), 'horas completas');
        $this->rechaza(fn () => $this->cotizador->cotizar($linea, '2026-12-01 10:00', '2026-12-01 10:59'), 'horas completas');
        $this->rechaza(fn () => $this->cotizador->cotizar($linea, '2026-12-01 10:00:30', '2026-12-01 11:00:30'), 'segundos');
        $this->rechaza(fn () => $this->cotizador->cotizar($linea, '2026-12-01 10:00', '2026-12-01 10:00'), 'posterior');
        $this->rechaza(fn () => $this->cotizador->cotizar($linea, '2026-12-01 12:00', '2026-12-01 10:00'), 'posterior');
        $this->rechaza(fn () => $this->cotizador->cotizar($linea, 'no-es-fecha', '2026-12-01 10:00'), 'no son válidas');

        // Horas completas aunque no empiecen en punto
        $this->assertSame(2, $this->cotizador->cotizar($linea, '2026-12-01 10:30', '2026-12-01 12:30')->horas);
    }

    public function test_horas_minimas_y_maximas_del_paquete(): void
    {
        $p = $this->paquete([$this->equipo()->id => 1], '50.00', ['horas_minimas' => 3, 'horas_maximas' => 8]);
        $linea = [['paquete_id' => $p->id]];

        $this->rechaza(fn () => $this->cotizador->cotizar($linea, '2026-12-01 10:00', '2026-12-01 12:00'), 'como mínimo 3');
        $this->rechaza(fn () => $this->cotizador->cotizar($linea, '2026-12-01 10:00', '2026-12-01 19:00'), 'como máximo 8');
        $this->assertSame(3, $this->cotizador->cotizar($linea, '2026-12-01 10:00', '2026-12-01 13:00')->horas);
        $this->assertSame(8, $this->cotizador->cotizar($linea, '2026-12-01 10:00', '2026-12-01 18:00')->horas);
    }

    public function test_el_paquete_debe_estar_activo_con_equipos_y_en_bob(): void
    {
        $m = $this->equipo();
        $inactivo = $this->paquete([$m->id => 1], '50.00', ['activo' => false]);
        $vacio = $this->paquete([], '50.00');
        $dolares = $this->paquete([$m->id => 1], '50.00', ['moneda' => 'USD']);
        $baja = $this->paquete([$m->id => 1]);
        $baja->delete(); // baja lógica

        $cotizar = fn ($id) => $this->cotizador->cotizar([['paquete_id' => $id]], '2026-12-01 10:00', '2026-12-01 12:00');
        $this->rechaza(fn () => $cotizar($inactivo->id), 'no está disponible');
        $this->rechaza(fn () => $cotizar($vacio->id), 'no tiene equipos');
        $this->rechaza(fn () => $cotizar($dolares->id), 'no tiene precio en BOB');
        $this->rechaza(fn () => $cotizar($baja->id), 'no existe o fue dado de baja');
        $this->rechaza(fn () => $cotizar(999999), 'no existe o fue dado de baja');
    }

    public function test_valida_las_lineas(): void
    {
        $p = $this->paquete([$this->equipo()->id => 1]);
        $cotizar = fn (array $lineas) => $this->cotizador->cotizar($lineas, '2026-12-01 10:00', '2026-12-01 12:00');

        $this->rechaza(fn () => $cotizar([]), 'al menos un paquete');
        $this->rechaza(fn () => $cotizar([['paquete_id' => $p->id, 'cantidad' => 0]]), 'mayor que cero');
        $this->rechaza(fn () => $cotizar([['paquete_id' => $p->id, 'cantidad' => 1.5]]), 'mayor que cero');
        $this->rechaza(fn () => $cotizar([['paquete_id' => 'abc']]), 'paquete válido');
        $this->rechaza(fn () => $cotizar([['cantidad' => 2]]), 'paquete válido');
    }

    public function test_un_paquete_repetido_se_agrupa(): void
    {
        $m = $this->equipo(5);
        $p = $this->paquete([$m->id => 2], '10.00');

        $c = $this->cotizador->cotizar([
            ['paquete_id' => $p->id, 'cantidad' => 1],
            ['paquete_id' => $p->id, 'cantidad' => 2],
        ], '2026-12-01 10:00', '2026-12-01 12:00');

        $this->assertCount(1, $c->lineas);
        $this->assertSame(3, $c->lineas[$p->id]['cantidad']);
        $this->assertSame('60.00', $c->total->decimal());
        $this->assertSame([$m->id => 6], $c->requerimientos);
    }

    public function test_el_precio_queda_congelado_en_la_cotizacion(): void
    {
        $p = $this->paquete([$this->equipo()->id => 1], '80.00');
        $c = $this->cotizador->cotizar([['paquete_id' => $p->id, 'cantidad' => 2]], '2026-12-01 10:00', '2026-12-01 12:00');

        $p->update(['precio_hora' => '95.00']);

        $this->assertSame('320.00', $c->total->decimal());
        $this->assertSame([[
            'paquete_id' => $p->id, 'cantidad' => 2, 'precio_hora' => '80.00', 'horas' => 2, 'subtotal' => '320.00',
        ]], $c->filasReservaPaquetes());
        $this->assertSame('380.00', $this->cotizador->cotizar([['paquete_id' => $p->id, 'cantidad' => 2]], '2026-12-01 10:00', '2026-12-01 12:00')->total->decimal());
    }

    public function test_cotizar_no_crea_reservas_ni_bloquea_equipos(): void
    {
        $p = $this->paquete([$this->equipo()->id => 1]);
        $this->cotizador->cotizar([['paquete_id' => $p->id]], '2026-12-01 10:00', '2026-12-01 12:00');

        $this->assertSame(0, Reserva::count());
        $this->assertSame(0, ReservaEquipo::count());
    }
}
