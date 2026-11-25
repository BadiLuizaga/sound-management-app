@extends('layouts.ferreteria')

@section('title', 'Reportes financieros')

@section('content')
<div class="card animate-fade-up" style="padding: 24px; margin-top: 20px;">
    <h2 style="margin-bottom: 4px;">Reportes financieros</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        Importes en BOB, filtrados por el período seleccionado.
    </p>

    @include('finance::partials.mensajes')

    <form method="GET" action="{{ route('finanzas.admin.reportes.index') }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px;">
        <select name="reporte">
            @foreach($reportes as $clave => $titulo)
                <option value="{{ $clave }}" @selected($clave === $reporte)>{{ $titulo }}</option>
            @endforeach
        </select>
        <label>Desde <input type="date" name="desde" value="{{ $desde }}"></label>
        <label>Hasta <input type="date" name="hasta" value="{{ $hasta }}"></label>
        <button type="submit" class="btn-action">Ver</button>
        <a href="{{ route('finanzas.admin.reportes.csv', ['reporte' => $reporte, 'desde' => $desde, 'hasta' => $hasta]) }}" class="btn-save" style="text-decoration: none;">Descargar CSV</a>
    </form>

    <h3>{{ $reportes[$reporte] }}</h3>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #e2e8f0;">
                    @foreach($tabla['columnas'] as $col)<th style="padding: 8px;">{{ $col }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($tabla['filas'] as $fila)
                    <tr style="border-bottom: 1px solid #f1f5f9; {{ ($fila[0] ?? '') === 'TOTAL' ? 'font-weight: bold;' : '' }}">
                        @foreach($fila as $celda)<td style="padding: 8px;">{{ $celda }}</td>@endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($tabla['columnas']) }}" style="padding: 24px; text-align: center; color: var(--text-muted);">Sin resultados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
