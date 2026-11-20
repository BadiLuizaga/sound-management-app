<?php

namespace Modules\Finance\Tests\Unit;

use Modules\Finance\Support\Dinero;
use PHPUnit\Framework\TestCase;

/**
 * Etapa 2 — paso 3: importes en centavos y conversión explícita BOB ↔ USD.
 */
class DineroTest extends TestCase
{
    public function test_redondeo_half_up_a_centavos(): void
    {
        $this->assertSame(12345, Dinero::deDecimal('123.45', 'BOB')->centavos);
        $this->assertSame(12346, Dinero::deDecimal('123.455', 'BOB')->centavos);
        $this->assertSame(12345, Dinero::deDecimal('123.454', 'BOB')->centavos);
        $this->assertSame(1, Dinero::deDecimal('0.005', 'BOB')->centavos);
        $this->assertSame('100.10', Dinero::deDecimal('100.1', 'BOB')->decimal());
    }

    public function test_bob_a_usd_con_tasa_explicita(): void
    {
        // 696.00 BOB / 6.96 = 100.00 USD
        $this->assertSame('100.00', Dinero::deDecimal('696.00', 'BOB')->convertirDividiendo('6.960000', 'USD')->decimal());
        // 100.00 / 6.96 = 14.3678... → 14.37 (HALF_UP)
        $this->assertSame('14.37', Dinero::deDecimal('100.00', 'BOB')->convertirDividiendo('6.96', 'USD')->decimal());
        // 10.00 / 6.97 = 1.43472... → 1.43
        $this->assertSame('1.43', Dinero::deDecimal('10.00', 'BOB')->convertirDividiendo('6.97', 'USD')->decimal());
        // Caso de medio centavo exacto: 0.03 / 6 = 0.005 → 0.01 (HALF_UP)
        $this->assertSame('0.01', Dinero::deDecimal('0.03', 'BOB')->convertirDividiendo('6', 'USD')->decimal());
    }

    public function test_usd_a_bob_con_la_misma_tasa(): void
    {
        $this->assertSame('100.08', Dinero::deDecimal('14.38', 'USD')->convertirMultiplicando('6.96', 'BOB')->decimal());
    }

    public function test_no_opera_monedas_distintas_sin_conversion(): void
    {
        $this->expectException(\LogicException::class);
        Dinero::deDecimal('1', 'BOB')->sumar(Dinero::deDecimal('1', 'USD'));
    }

    public function test_tasa_invalida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dinero::deDecimal('10', 'BOB')->convertirDividiendo('0', 'USD');
    }
}
