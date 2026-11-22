<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Corte de caja #{{ $caja->id }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 14px 0 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 6px; text-align: left; }
        th { background: #f1f5f9; }
        .r { text-align: right; }
        .total td { font-weight: bold; border-top: 1px solid #94a3b8; }
        .aviso { background: #fef2f2; padding: 6px; margin-top: 8px; }
        .nota { background: #fffbeb; padding: 6px; margin-top: 8px; }
    </style>
</head>
<body>
    <h1>Corte de caja N.º {{ str_pad($caja->id, 5, '0', STR_PAD_LEFT) }} {{ $caja->estaAbierta() ? '(PROVISIONAL: caja abierta)' : '' }}</h1>
    <table>
        <tr><td><strong>Titular:</strong> {{ $caja->user?->nombre ?? $caja->user_id }}</td><td class="r"><strong>Apertura:</strong> {{ $caja->fecha_apertura?->format('d/m/Y H:i') }}</td></tr>
        <tr>
            <td><strong>Cerrada por:</strong> {{ $caja->cerradoPor?->nombre ?? '—' }}{{ $caja->cierre_supervisado ? ' (cierre supervisado)' : '' }}</td>
            <td class="r"><strong>Cierre:</strong> {{ $caja->fecha_cierre?->format('d/m/Y H:i') ?? 'Pendiente' }}</td>
        </tr>
    </table>

    <h2>Arqueo de efectivo (BOB)</h2>
    <table>
        <tr><td>Fondo de apertura</td><td class="r">{{ $foto['fondo'] }}</td></tr>
        <tr><td>+ Cobros en efectivo</td><td class="r">{{ $foto['efectivo_cobrado'] }}</td></tr>
        <tr><td>− Reembolsos entregados en efectivo</td><td class="r">{{ $foto['reembolsos_efectivo'] }}</td></tr>
        <tr><td>+ Ingresos manuales</td><td class="r">{{ $foto['ingresos'] }}</td></tr>
        <tr><td>− Egresos manuales</td><td class="r">{{ $foto['egresos'] }}</td></tr>
        <tr class="total"><td>Efectivo esperado</td><td class="r">{{ $foto['efectivo_esperado'] }}</td></tr>
        @isset($foto['efectivo_contado'])
            <tr><td>Efectivo contado</td><td class="r">{{ $foto['efectivo_contado'] }}</td></tr>
            <tr class="total"><td>Diferencia ({{ (float) $foto['diferencia'] < 0 ? 'faltante' : ((float) $foto['diferencia'] > 0 ? 'sobrante' : 'sin diferencia') }})</td><td class="r">{{ $foto['diferencia'] }}</td></tr>
        @endisset
    </table>

    <h2>Cobros por método (informativo; solo el efectivo entra al arqueo)</h2>
    <table>
        <tr><th>Método</th><th class="r">Recibido</th><th class="r">Operaciones</th><th class="r">Pendiente</th><th class="r">Rechazado</th></tr>
        @foreach($foto['metodos'] as $m)
            <tr><td>{{ $m['nombre'] }}</td><td class="r">{{ $m['recibido'] }}</td><td class="r">{{ $m['operaciones'] }}</td><td class="r">{{ $m['pendiente'] }} ({{ $m['pendientes'] }})</td><td class="r">{{ $m['rechazado'] }} ({{ $m['rechazados'] }})</td></tr>
        @endforeach
    </table>

    @if(!empty($foto['reembolsos_por_medio']))
        <h2>Reembolsos entregados desde la caja</h2>
        <table>
            @foreach($foto['reembolsos_por_medio'] as $medio => $r)
                <tr><td>{{ ucfirst($medio) }}{{ $medio !== 'efectivo' ? ' (no resta efectivo)' : '' }}</td><td class="r">{{ $r['monto'] }} ({{ $r['cantidad'] }})</td></tr>
            @endforeach
        </table>
    @endif

    @if(!empty($foto['pendientes']))
        <div class="nota"><strong>Pendientes de verificación al cierre:</strong>
            @foreach($foto['pendientes'] as $p) {{ $p['metodo'] }} {{ $p['monto'] }} (ref. {{ $p['referencia'] ?? '—' }})@if(!$loop->last);@endif @endforeach
        </div>
    @endif

    @if(!empty($foto['alquileres_fuera_del_arqueo']))
        <div class="aviso"><strong>Alquileres heredados NO incluidos en el arqueo:</strong>
            @foreach($foto['alquileres_fuera_del_arqueo'] as $al) #{{ $al['alquiler'] }} {{ $al['metodo'] }} estimado {{ $al['total_estimado'] }}, garantía {{ $al['garantia'] }}@if(!$loop->last);@endif @endforeach
        </div>
    @endif

    @if($caja->observaciones)
        <h2>Observaciones</h2>
        <p>{{ $caja->observaciones }}</p>
    @endif

    <p style="margin-top: 24px; font-size: 9px; color: #64748b;">Generado el {{ now()->format('d/m/Y H:i') }}.</p>
</body>
</html>
