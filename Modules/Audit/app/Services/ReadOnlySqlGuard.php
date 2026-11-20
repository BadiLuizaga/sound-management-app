<?php

namespace Modules\Audit\Services;

/**
 * Valida que una consulta generada por IA sea un único SELECT de solo
 * lectura que solo toque tablas de negocio permitidas y nunca columnas
 * sensibles. Es una capa de defensa: además se recomienda un usuario MySQL
 * con permiso SELECT restringido (conexión 'mysql_reports').
 */
class ReadOnlySqlGuard
{
    /**
     * Tablas que el modelo puede consultar (comparación sin mayúsculas).
     */
    public const ALLOWED_TABLES = [
        'producto', 'marca', 'categoria', 'color', 'medida', 'volumen',
        'usuario', 'empleado', 'cliente', 'rol', 'estadorol',
        'notaventa', 'detallenotaventa', 'notacompra', 'detallenotacompra', 'proveedor',
        'metodopago', 'bitacoras', 'cajas',
        'maquinarias', 'alquileres', 'alquiler_detalles', 'mantenimientos',
        'devoluciones', 'devolucion_detalles',
        'pedidos_reabastecimiento', 'pedido_reabastecimiento_detalles',
        'promociones', 'promocion_productos',
        'cotizaciones', 'cotizacion_detalles',
    ];

    /**
     * Palabras que nunca pueden aparecer (escritura, archivos, bloqueos,
     * esquemas del sistema, columnas/tablas sensibles).
     */
    private const FORBIDDEN_PATTERNS = [
        '/\b(insert|update|delete|replace|merge|upsert|drop|alter|create|truncate|rename|grant|revoke|call|exec|execute|prepare|deallocate|handler|load|lock|unlock|set|do|use|kill|shutdown|flush|reset|purge|install|uninstall|analyze|optimize|repair|checksum)\b/i',
        '/\binto\b/i',                       // SELECT ... INTO OUTFILE / variables
        '/\b(outfile|dumpfile|load_file|sleep|benchmark|get_lock|release_lock|is_free_lock|master_pos_wait|source_pos_wait)\b/i',
        '/\bfor\s+update\b/i',
        '/\block\s+in\s+share\s+mode\b/i',
        '/\bfor\s+share\b/i',
        '/\b(information_schema|performance_schema|mysql|sys)\s*\./i',
        '/\b(information_schema|performance_schema)\b/i',
        '/\b(password|remember_token|payload|token)\b/i',
        '/\b(sessions|users|password_reset_tokens|cache|cache_locks|jobs|job_batches|failed_jobs|migrations|pagos_externos|carritos)\b/i',
        '/@@|@[a-z_]/i',                     // variables de sistema o de usuario
    ];

    /**
     * @return string consulta normalizada (sin espacios sobrantes)
     *
     * @throws \InvalidArgumentException si la consulta no es segura
     */
    public function validate(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, " \t\n\r\0\x0B;");

        if ($sql === '' || mb_strlen($sql) > 4000) {
            throw new \InvalidArgumentException('Consulta vacía o demasiado larga.');
        }

        if (!preg_match('/^select\b/i', $sql)) {
            throw new \InvalidArgumentException('Solo se permiten consultas SELECT.');
        }

        // Una sola sentencia y sin comentarios (evita "SELECT 1; DROP ..." y ofuscación)
        if (str_contains($sql, ';') || preg_match('/(--|#|\/\*|\*\/)/', $sql)) {
            throw new \InvalidArgumentException('La consulta contiene varias sentencias o comentarios.');
        }

        // Sin literales hexadecimales/binarios ni backslashes (ofuscación de nombres)
        if (preg_match("/\\\\|\\b0x[0-9a-f]+|\\bx'|\\bb'/i", $sql)) {
            throw new \InvalidArgumentException('La consulta contiene expresiones no permitidas.');
        }

        // Para revisar palabras clave sin falsos positivos dentro de textos
        // ('...'), se analizan los literales vaciados.
        $sinLiterales = preg_replace("/'(?:[^']|'')*'|\"(?:[^\"]|\"\")*\"/", "''", $sql) ?? '';

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $sinLiterales)) {
                throw new \InvalidArgumentException('La consulta usa operaciones, tablas o columnas no permitidas.');
            }
        }

        // SELECT * expone todas las columnas (incluidas las sensibles): solo se
        // permite dentro de COUNT(*).
        $sinCount = preg_replace('/count\s*\(\s*\*\s*\)/i', 'count(1)', $sinLiterales) ?? '';
        if (str_contains($sinCount, '*')) {
            throw new \InvalidArgumentException('Indica las columnas explícitamente (no se permite SELECT *).');
        }

        // Todas las tablas después de FROM/JOIN deben estar permitidas.
        // Las uniones con coma se cubren porque cualquier nombre de tabla
        // sensible está prohibido arriba.
        // EXTRACT(YEAR FROM columna) y TRIM(... FROM ...) no son tablas.
        $paraTablas = preg_replace('/\b(extract|trim|substring)\s*\([^()]*\)/i', 'f()', $sinLiterales) ?? '';
        preg_match_all('/\b(?:from|join)\s+`?([a-z_][a-z0-9_]*)`?/i', $paraTablas, $m);
        if (empty($m[1])) {
            throw new \InvalidArgumentException('La consulta no indica tablas.');
        }
        foreach ($m[1] as $tabla) {
            if (!in_array(strtolower($tabla), self::ALLOWED_TABLES, true)) {
                throw new \InvalidArgumentException("La tabla '{$tabla}' no está permitida en reportes.");
            }
        }

        return preg_replace('/\s+/', ' ', $sql) ?? $sql;
    }
}
