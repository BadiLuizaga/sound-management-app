<?php

namespace Modules\Finance\Support;

/**
 * Importe monetario inmutable en CENTAVOS enteros. Evita errores de coma
 * flotante en sumas y comparaciones de dinero.
 */
final class Dinero
{
    private function __construct(
        public readonly int $centavos,
        public readonly string $moneda,
    ) {
        if (!preg_match('/^[A-Z]{3}$/', $moneda)) {
            throw new \InvalidArgumentException("Moneda inválida: {$moneda}");
        }
    }

    public static function deCentavos(int $centavos, string $moneda): self
    {
        return new self($centavos, strtoupper($moneda));
    }

    /**
     * Desde un decimal ("123.45", 123.45). Redondeo HALF_UP a 2 decimales.
     */
    public static function deDecimal(string|int|float $valor, string $moneda): self
    {
        if (is_string($valor) && !is_numeric($valor)) {
            throw new \InvalidArgumentException("Importe inválido: {$valor}");
        }

        return new self(self::redondearACentavos((string) $valor), strtoupper($moneda));
    }

    public static function cero(string $moneda): self
    {
        return new self(0, strtoupper($moneda));
    }

    public function sumar(self $otro): self
    {
        $this->mismaMoneda($otro);
        return new self($this->centavos + $otro->centavos, $this->moneda);
    }

    public function restar(self $otro): self
    {
        $this->mismaMoneda($otro);
        return new self($this->centavos - $otro->centavos, $this->moneda);
    }

    public function igualA(self $otro): bool
    {
        return $this->moneda === $otro->moneda && $this->centavos === $otro->centavos;
    }

    public function mayorQue(self $otro): bool
    {
        $this->mismaMoneda($otro);
        return $this->centavos > $otro->centavos;
    }

    public function esPositivo(): bool
    {
        return $this->centavos > 0;
    }

    /**
     * "123.45" (formato para BD y APIs).
     */
    public function decimal(): string
    {
        $signo = $this->centavos < 0 ? '-' : '';
        $abs = abs($this->centavos);

        return $signo . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Convierte BOB → otra moneda con una tasa "BOB por 1 unidad de la moneda
     * destino": destino = round(bob / tasa, 2) HALF_UP. Sin floats.
     */
    public function convertirDividiendo(string $tasa, string $monedaDestino): self
    {
        if (!is_numeric($tasa) || str_starts_with(trim($tasa), '-')) {
            throw new \InvalidArgumentException('La tasa de cambio debe ser mayor que cero.');
        }

        // centavos_destino = round(centavos_origen / tasa) con tasa de hasta 6 decimales
        [$entero, $fraccion] = array_pad(explode('.', trim($tasa), 2), 2, '');
        $fraccion = substr(str_pad($fraccion, 6, '0'), 0, 6);
        $tasaMicro = (int) (($entero === '' ? '0' : $entero) . $fraccion); // tasa × 1_000_000

        if ($tasaMicro <= 0) {
            throw new \InvalidArgumentException('La tasa de cambio debe ser mayor que cero.');
        }

        // (centavos × 1_000_000) / tasaMicro, redondeo HALF_UP
        $numerador = $this->centavos * 1_000_000;
        $cociente = intdiv($numerador, $tasaMicro);
        $resto = $numerador % $tasaMicro;
        if ($resto * 2 >= $tasaMicro) {
            $cociente++;
        }

        return new self($cociente, strtoupper($monedaDestino));
    }

    /**
     * Inversa: moneda del proveedor → BOB con la misma tasa "BOB por 1 unidad":
     * bob = round(importe × tasa, 2) HALF_UP. Sin floats.
     */
    public function convertirMultiplicando(string $tasa, string $monedaDestino): self
    {
        if (!is_numeric($tasa) || str_starts_with(trim($tasa), '-')) {
            throw new \InvalidArgumentException('La tasa de cambio debe ser mayor que cero.');
        }

        [$entero, $fraccion] = array_pad(explode('.', trim($tasa), 2), 2, '');
        $fraccion = substr(str_pad($fraccion, 6, '0'), 0, 6);
        $tasaMicro = (int) (($entero === '' ? '0' : $entero) . $fraccion);
        if ($tasaMicro <= 0) {
            throw new \InvalidArgumentException('La tasa de cambio debe ser mayor que cero.');
        }

        $producto = $this->centavos * $tasaMicro; // centavos × tasa × 1e6
        $cociente = intdiv($producto, 1_000_000);
        if (($producto % 1_000_000) * 2 >= 1_000_000) {
            $cociente++;
        }

        return new self($cociente, strtoupper($monedaDestino));
    }

    private function mismaMoneda(self $otro): void
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \LogicException("No se pueden operar {$this->moneda} y {$otro->moneda} sin una conversión explícita.");
        }
    }

    private static function redondearACentavos(string $valor): int
    {
        $valor = trim($valor);
        $negativo = str_starts_with($valor, '-');
        $valor = ltrim($valor, '+-');

        if (stripos($valor, 'e') !== false) {
            $valor = number_format((float) $valor, 6, '.', '');
        }

        [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
        $fraccion = str_pad($fraccion, 3, '0');
        $centavos = ((int) ($entero === '' ? '0' : $entero)) * 100 + (int) substr($fraccion, 0, 2);
        if ((int) $fraccion[2] >= 5) {
            $centavos++; // HALF_UP
        }

        return $negativo ? -$centavos : $centavos;
    }
}
