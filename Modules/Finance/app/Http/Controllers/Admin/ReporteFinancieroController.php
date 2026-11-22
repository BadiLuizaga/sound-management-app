<?php

namespace Modules\Finance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Modules\Finance\Services\ReporteFinancieroService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes financieros y conciliación (D8: pantalla y CSV).
 * Permiso finance.reports.view (aplicado en las rutas).
 */
class ReporteFinancieroController extends Controller
{
    public function index(Request $request, ReporteFinancieroService $servicio)
    {
        [$reporte, $desde, $hasta] = $this->parametros($request);

        return view('finance::reportes.index', [
            'reportes' => ReporteFinancieroService::REPORTES,
            'reporte' => $reporte,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'tabla' => $servicio->generar($reporte, $desde, $hasta),
        ]);
    }

    public function csv(Request $request, ReporteFinancieroService $servicio): StreamedResponse
    {
        [$reporte, $desde, $hasta] = $this->parametros($request);
        $tabla = $servicio->generar($reporte, $desde, $hasta);
        $nombre = "{$reporte}_{$desde->toDateString()}_{$hasta->toDateString()}.csv";

        return response()->streamDownload(function () use ($tabla) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // BOM: Excel reconoce UTF-8 (tildes)
            fputcsv($salida, $tabla['columnas']);
            foreach ($tabla['filas'] as $fila) {
                fputcsv($salida, array_map([self::class, 'celdaSegura'], $fila));
            }
            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Evita la inyección de fórmulas en hojas de cálculo: un texto que empieza
     * por = + - @ (o tabulación/retorno) se precede de un apóstrofo. Los números
     * (incluidos los negativos, como "-5.00") se dejan tal cual.
     */
    public static function celdaSegura(mixed $valor): string
    {
        $texto = (string) $valor;
        if ($texto !== '' && !is_numeric($texto) && preg_match('/^[=+\-@\t\r]/', $texto)) {
            return "'" . $texto;
        }

        return $texto;
    }

    private function parametros(Request $request): array
    {
        $request->validate([
            'reporte' => ['nullable', 'in:' . implode(',', array_keys(ReporteFinancieroService::REPORTES))],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        $zona = config('finance.zona_horaria', 'America/La_Paz');
        $hasta = $request->filled('hasta') ? Carbon::createFromFormat('Y-m-d', $request->hasta, $zona) : Carbon::now($zona);
        $desde = $request->filled('desde') ? Carbon::createFromFormat('Y-m-d', $request->desde, $zona) : $hasta->copy()->subDays(30);

        if ($desde->diffInDays($hasta, true) > 366) {
            abort(422, 'El período no puede superar un año.');
        }

        return [$request->input('reporte', 'cobros_por_dia'), $desde, $hasta];
    }
}
