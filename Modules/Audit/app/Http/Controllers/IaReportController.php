<?php

namespace Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Models\Bitacora;
use Modules\Audit\Services\ReadOnlySqlGuard;

/**
 * Reportes en lenguaje natural → SQL de solo lectura.
 *
 * Fase 1 (seguridad):
 *  - Solo administradores con la habilidad 'reports.ai' (ver rutas).
 *  - Desactivado por defecto (services.groq.enabled).
 *  - La consulta generada se valida con ReadOnlySqlGuard (un único SELECT,
 *    tablas permitidas, sin columnas sensibles, sin SELECT *).
 *  - Se ejecuta en la conexión 'mysql_reports' dentro de una transacción
 *    READ ONLY, con límite de tiempo y de filas, y se revierte siempre.
 *  - Cada consulta queda registrada en la bitácora.
 *  - Los errores internos no se devuelven al navegador.
 */
class IaReportController extends Controller
{
    public function index()
    {
        return view('bitacora.ia-reports');
    }

    public function consultar(Request $request, ReadOnlySqlGuard $guard)
    {
        $request->validate([
            'consulta' => 'required|string|max:500',
        ]);

        $config = config('services.groq');

        if (empty($config['enabled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Los reportes con IA están desactivados. Actívalos con AI_REPORTS_ENABLED=true.',
            ], 503);
        }

        if (empty($config['key'])) {
            return response()->json([
                'success' => false,
                'message' => 'La API Key de Groq no está configurada (GROQ_API_KEY).',
            ], 503);
        }

        $consulta = $request->input('consulta');
        $query = null;

        try {
            $response = Http::withToken($config['key'])
                ->acceptJson()
                ->timeout(20)
                ->post($config['url'], [
                    'model' => $config['model'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $consulta],
                    ],
                    'temperature' => 0.0,
                ]);

            if ($response->failed()) {
                Log::warning('IA reportes: error del proveedor', ['status' => $response->status()]);
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo contactar al servicio de IA.',
                ], 502);
            }

            $query = trim((string) ($response->json('choices.0.message.content') ?? ''));
            // Limpieza de formato markdown de código
            $query = preg_replace('/^```(sql)?/i', '', $query);
            $query = preg_replace('/```$/', '', $query);
            $query = trim($query);

            try {
                $query = $guard->validate($query);
            } catch (\InvalidArgumentException $e) {
                $this->registrar('RECHAZADA', $consulta, $query, $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Consulta rechazada por seguridad: ' . $e->getMessage(),
                    'query' => $query,
                ], 422);
            }

            $results = $this->ejecutarSoloLectura($query, (string) $config['connection'], (int) $config['timeout_ms']);

            $maxRows = max(1, (int) $config['max_rows']);
            $truncado = count($results) > $maxRows;
            $results = array_slice($results, 0, $maxRows);

            $columns = [];
            if (!empty($results)) {
                $columns = array_keys((array) $results[0]);
            }

            $this->registrar('CONSULTAR', $consulta, $query, count($results) . ' filas' . ($truncado ? ' (truncado)' : ''));

            return response()->json([
                'success' => true,
                'query' => $query,
                'columns' => $columns,
                'data' => $results,
                'truncated' => $truncado,
            ]);
        } catch (\Throwable $e) {
            Log::error('IA reportes: error al procesar la consulta', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el reporte con IA.',
                'query' => $query,
            ], 500);
        }
    }

    /**
     * Ejecuta la consulta en una transacción de solo lectura que siempre se
     * revierte, con un tiempo máximo de ejecución para SELECT.
     */
    private function ejecutarSoloLectura(string $query, string $connection, int $timeoutMs): array
    {
        $db = DB::connection($connection);

        if ($db->getDriverName() === 'mysql') {
            $db->statement('SET SESSION MAX_EXECUTION_TIME = ' . max(100, $timeoutMs));
            // Aplica a la siguiente transacción: cualquier escritura fallará.
            $db->statement('SET TRANSACTION READ ONLY');
        }

        $db->beginTransaction();
        try {
            return $db->select($query);
        } finally {
            $db->rollBack();
        }
    }

    private function registrar(string $accion, string $pregunta, ?string $query, string $resultado): void
    {
        try {
            Bitacora::registrar(
                'IA_' . $accion,
                'reportes_ia',
                0,
                mb_substr("Pregunta: {$pregunta} | SQL: {$query} | Resultado: {$resultado} | CI: " . (Auth::user()->ci ?? '-'), 0, 4000)
            );
        } catch (\Throwable $e) {
            Log::warning('IA reportes: no se pudo registrar en bitácora', ['error' => $e->getMessage()]);
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert system that translates natural language questions in Spanish into MySQL SELECT queries for a business database.
The database tables are:
1. `producto` (idproducto INT, nombre VARCHAR, descripcion TEXT, precio DECIMAL, cantidad INT, fechacaducidad DATE, id_marca INT, id_categoria INT, id_color INT, id_medida INT, id_volumen INT, costo DECIMAL, deleted_at TIMESTAMP)
2. `usuario` (ci INT, nombre VARCHAR, apellido VARCHAR, telefono INT, sexo CHAR, email VARCHAR, domicilio VARCHAR, tipoPersona VARCHAR)
3. `empleado` (ci INT, salario DECIMAL, estado VARCHAR) - Inherits from usuario by ci.
4. `cliente` (ci INT, puntos INT, categoria VARCHAR) - Inherits from usuario by ci.
5. `marca` (id INT, nombre VARCHAR)
6. `categoria` (idcategoria INT, nombre VARCHAR, descripcion TEXT, id_categoria_padre INT)
7. `NotaVenta` (nro INT, fecha DATETIME, total DECIMAL, ci_cliente INT, ci_empleado INT, id_pago INT)
8. `detalleNotaVenta` (nro_factura INT, id_producto INT, precio_unitario DECIMAL, cantidad INT, descuento DECIMAL)
9. `NotaCompra` (nro INT, fecha DATETIME, total DECIMAL, ci_proveedor INT, id_pago INT)
10. `detalleNotaCompra` (nro_factura INT, id_producto INT, precio_unitario DECIMAL, cantidad INT)
11. `bitacoras` (id INT, usuario VARCHAR, accion VARCHAR, tabla VARCHAR, registro_id INT, descripcion TEXT, ip VARCHAR, created_at TIMESTAMP)
12. `cajas` (id INT, user_id INT, monto_apertura DECIMAL, monto_cierre DECIMAL, diferencia DECIMAL, estado VARCHAR, fecha_apertura DATETIME, fecha_cierre DATETIME, version_arqueo INT [1 = arqueo heredado sin desglose, 2 = arqueo por método], efectivo_esperado DECIMAL, efectivo_contado DECIMAL, ci_cerrado_por INT, cierre_supervisado BOOLEAN)
13. `alquileres` (id INT, ci_cliente INT, ci_empleado INT, fecha_inicio DATETIME, fecha_fin_estimada DATETIME, fecha_devolucion DATETIME, total_estimado DECIMAL, total_real DECIMAL, estado VARCHAR)
14. `devoluciones` (id INT, nro_factura INT, tipo VARCHAR, motivo TEXT, fecha DATE, estado VARCHAR, ci_empleado INT, observaciones TEXT)
15. `devolucion_detalles` (id INT, devolucion_id INT, idproducto INT, cantidad INT)
16. `maquinarias` (id INT, codigo VARCHAR, nombre VARCHAR, descripcion TEXT, precio_hora DECIMAL, precio_dia DECIMAL, garantia_sugerida DECIMAL, estado VARCHAR)
17. `alquiler_detalles` (id INT, alquiler_id INT, maquinaria_id INT, precio_unitario DECIMAL, tipo_tarifa VARCHAR, tiempo_rentado INT)
18. `mantenimientos` (id INT, idproducto INT, cantidad INT, tipo VARCHAR, descripcion TEXT, costo DECIMAL, fecha_inicio DATE, fecha_fin DATE, estado VARCHAR, ci_responsable INT, observaciones TEXT)
19. `pedidos_reabastecimiento` (id INT, ci_empleado INT, fecha DATE, estado VARCHAR, observaciones TEXT)
20. `pedido_reabastecimiento_detalles` (id INT, pedido_id INT, idproducto INT, cantidad_sugerida INT)
21. `promociones` (id INT, nombre VARCHAR, descripcion TEXT, tipo VARCHAR, descuento_porcentaje DECIMAL, precio_combo DECIMAL, fecha_inicio DATE, fecha_fin DATE, estado VARCHAR)
22. `promocion_productos` (id INT, promocion_id INT, idproducto INT)
23. `cotizaciones` (id INT, ci_cliente INT, fecha DATE, total DECIMAL, observaciones TEXT)
24. `cotizacion_detalles` (id INT, cotizacion_id INT, idproducto INT, cantidad INT, precio_unitario DECIMAL)
Rules:
1. Generate ONLY one valid MySQL SELECT query.
2. DO NOT wrap the query in markdown, do not include any explanatory text or comments. Just the query string, without a trailing semicolon.
3. Only generate SELECT queries. Never generate INSERT, UPDATE, DELETE, DROP, or other write queries, and never use INTO, variables or locking clauses.
4. Keep the table names exactly as defined above (case-sensitive). Never query any other table.
5. Never use SELECT * (COUNT(*) is allowed). Always list the columns explicitly.
6. For relationships, perform joins as necessary. E.g., to join producto and marca, use `producto.id_marca = marca.id`. E.g., to join NotaVenta and customer names, join `NotaVenta` with `usuario` on `ci_cliente = ci`.
7. Make column aliases user-friendly, like `SELECT nombre AS Producto, cantidad AS Stock FROM producto`.
8. Add LIMIT 500 unless the question asks for an aggregate.
PROMPT;
    }
}
