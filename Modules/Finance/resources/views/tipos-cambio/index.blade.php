@extends('layouts.ferreteria')

@section('title', 'Tipos de cambio')

@section('content')
<div class="card animate-fade-up" style="padding: 24px; margin-top: 20px;">
    <h2 style="margin-bottom: 4px;">Tipos de cambio (BOB por 1 USD)</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        Vigencia diaria. Sin tasa para hoy ({{ $hoy }}) no se ofrece el pago en línea. Una tasa usada por pagos no se puede modificar.
        @if($vigente)
            <br>Tasa de hoy: <strong>{{ $vigente->tasa }}</strong>
        @else
            <br><strong style="color: #B91C1C;">No hay tasa registrada para hoy.</strong>
        @endif
    </p>

    @include('finance::partials.mensajes')

    <form method="POST" action="{{ route('finanzas.admin.tipos-cambio.store') }}" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;">
        @csrf
        <input type="date" name="fecha_aplicacion" value="{{ old('fecha_aplicacion', $hoy) }}" required>
        <input type="text" name="tasa" placeholder="Ej.: 6.96" value="{{ old('tasa') }}" required pattern="\d{1,6}(\.\d{1,6})?">
        <input type="text" name="fuente" placeholder="Fuente (opcional)" value="{{ old('fuente') }}" maxlength="150">
        <button type="submit" class="btn-save">Registrar tasa</button>
    </form>

    <table style="width: 100%; border-collapse: collapse;">
        <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Fecha</th><th>Tasa</th><th>Fuente</th><th>Pagos que la usan</th><th>Revisiones</th><th>Modificar</th></tr></thead>
        <tbody>
            @forelse($tasas as $tc)
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td>{{ $tc->fecha_aplicacion->format('d/m/Y') }}</td>
                    <td><strong>{{ $tc->tasa }}</strong></td>
                    <td>{{ $tc->fuente ?? '—' }}</td>
                    <td>{{ $tc->pagos_count }}</td>
                    <td>{{ $tc->revisiones->count() }}</td>
                    <td>
                        @if($tc->pagos_count === 0)
                            <form method="POST" action="{{ route('finanzas.admin.tipos-cambio.update', $tc) }}" style="display: flex; gap: 6px;">
                                @csrf @method('PUT')
                                <input type="text" name="tasa" value="{{ $tc->tasa }}" required pattern="\d{1,6}(\.\d{1,6})?" style="width: 110px;">
                                <input type="text" name="motivo" placeholder="Motivo" required maxlength="255">
                                <button type="submit">Guardar</button>
                            </form>
                        @else
                            <span style="color: var(--text-muted);">En uso: no modificable</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="padding: 12px; color: var(--text-muted);">No hay tasas registradas.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 16px;">{{ $tasas->links() }}</div>
</div>
@endsection
