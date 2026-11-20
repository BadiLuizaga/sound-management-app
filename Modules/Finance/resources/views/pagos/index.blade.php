@extends('layouts.ferreteria')

@section('title', 'Pagos')

@section('content')
<div class="card animate-fade-up" style="padding: 24px; margin-top: 20px;">
    <h2 style="margin-bottom: 4px;">Pagos</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        {{ $verTodos ? 'Todos los pagos del sistema.' : 'Pagos de caja registrados por ti.' }} Importes en BOB.
    </p>

    @include('finance::partials.mensajes')

    <form method="GET" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <select name="estado">
            <option value="">Estado del dinero: todos</option>
            @foreach($estados as $e)
                <option value="{{ $e->value }}" @selected(request('estado') === $e->value)>{{ $e->value }}</option>
            @endforeach
        </select>
        <select name="aplicacion">
            <option value="">Aplicación: todas</option>
            @foreach($aplicaciones as $a)
                <option value="{{ $a->value }}" @selected(request('aplicacion') === $a->value)>{{ $a->value }}</option>
            @endforeach
        </select>
        <select name="metodo">
            <option value="">Método: todos</option>
            @foreach($metodos as $m)
                <option value="{{ $m->id }}" @selected((string) request('metodo') === (string) $m->id)>{{ $m->nombre }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-action">Filtrar</button>
    </form>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 8px;">Fecha</th>
                    <th style="padding: 8px;">Método</th>
                    <th style="padding: 8px;">Canal</th>
                    <th style="padding: 8px; text-align: right;">Importe (BOB)</th>
                    <th style="padding: 8px;">Dinero</th>
                    <th style="padding: 8px;">Aplicación</th>
                    <th style="padding: 8px;">Venta</th>
                    <th style="padding: 8px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($pagos as $p)
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 8px;">{{ $p->created_at?->format('d/m/Y H:i') }}</td>
                        <td style="padding: 8px;">{{ $p->metodoPago?->nombre }}</td>
                        <td style="padding: 8px;">{{ $p->canal }}</td>
                        <td style="padding: 8px; text-align: right;">{{ $p->monto }}</td>
                        <td style="padding: 8px;"><strong>{{ $p->estado->value }}</strong></td>
                        <td style="padding: 8px;">{{ $p->estado_aplicacion->value }}</td>
                        <td style="padding: 8px;">{{ $p->nro_factura ?? '—' }}</td>
                        <td style="padding: 8px;"><a href="{{ route('finanzas.admin.pagos.show', $p) }}">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="padding: 24px; text-align: center; color: var(--text-muted);">No hay pagos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 16px;">{{ $pagos->links() }}</div>
</div>
@endsection
