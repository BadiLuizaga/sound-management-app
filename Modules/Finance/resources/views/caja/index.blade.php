@extends('layouts.ferreteria')

@section('title', 'Caja')

@section('content')
<div class="card animate-fade-up" style="padding: 24px; margin-top: 20px;">
    <h2 style="margin-bottom: 4px;">Caja</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">Arqueo por caja y método de pago. Importes en BOB.</p>

    @include('finance::partials.mensajes')

    {{-- ── Mi caja ── --}}
    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 24px;">
        @if($miCaja)
            <h3 style="margin-top: 0;">Mi caja #{{ $miCaja->id }} — abierta desde {{ $miCaja->fecha_apertura->format('d/m/Y H:i') }}</h3>
            @include('finance::caja.partials.arqueo', ['a' => $miArqueo->toArray()])

            <div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start;">
                @can('cash.operate_own')
                    <form method="POST" action="{{ route('caja.corte', $miCaja) }}" style="display: flex; flex-direction: column; gap: 6px; min-width: 260px;">
                        @csrf
                        <input type="hidden" name="clave_cierre" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <strong>Cerrar mi caja</strong>
                        <label>Efectivo contado (Bs.) <input type="text" name="efectivo_contado" required pattern="\d{1,9}(\.\d{1,2})?" value="{{ old('efectivo_contado') }}"></label>
                        <textarea name="observaciones" maxlength="500" placeholder="Observaciones (obligatorias si hay diferencia)">{{ old('observaciones') }}</textarea>
                        <button type="submit" class="btn-save">Realizar cierre</button>
                    </form>
                @endcan

                @canany(['cash.movements.income', 'cash.movements.expense'])
                    <form method="POST" action="{{ route('caja.movimientos.store') }}" style="display: flex; flex-direction: column; gap: 6px; min-width: 260px;">
                        @csrf
                        <input type="hidden" name="clave_idempotencia" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <strong>Movimiento de efectivo</strong>
                        <select name="tipo" required>
                            @can('cash.movements.income')<option value="ingreso">Ingreso (p. ej., cambio)</option>@endcan
                            @can('cash.movements.expense')<option value="egreso">Egreso</option>@endcan
                        </select>
                        <input type="text" name="monto" placeholder="Importe" required pattern="\d{1,9}(\.\d{1,2})?">
                        <input type="text" name="motivo" placeholder="Motivo" required maxlength="255">
                        <button type="submit" class="btn-action">Registrar</button>
                    </form>
                @endcanany
            </div>
            <p style="margin-top: 12px;"><a href="{{ route('caja.show', $miCaja) }}">Ver detalle e historial</a> · <a href="{{ route('caja.reporte', $miCaja) }}">Corte en PDF</a></p>
        @else
            <h3 style="margin-top: 0;">No tienes una caja abierta</h3>
            @can('cash.operate_own')
                <form method="POST" action="{{ route('caja.apertura') }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    @csrf
                    <label>Fondo de apertura (Bs.) <input type="text" name="monto_apertura" required pattern="\d{1,9}(\.\d{1,2})?"></label>
                    <button type="submit" class="btn-save">Abrir caja</button>
                </form>
            @endcan
        @endif
    </div>

    {{-- ── Historial de cajas ── --}}
    <h3>{{ $verTodas ? 'Todas las cajas' : 'Mis cajas' }}</h3>
    @if($verTodas)
        <form method="GET" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;">
            <select name="estado">
                <option value="">Estado: todos</option>
                <option value="abierta" @selected(request('estado') === 'abierta')>Abiertas</option>
                <option value="cerrada" @selected(request('estado') === 'cerrada')>Cerradas</option>
            </select>
            <select name="titular">
                <option value="">Titular: todos</option>
                @foreach($titulares as $t)
                    <option value="{{ $t->ci }}" @selected((string) request('titular') === (string) $t->ci)>{{ $t->nombre }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-action">Filtrar</button>
        </form>
    @endif

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 8px;">#</th><th style="padding: 8px;">Titular</th><th style="padding: 8px;">Apertura</th>
                    <th style="padding: 8px; text-align: right;">Fondo</th><th style="padding: 8px;">Estado</th><th style="padding: 8px;">Cierre</th>
                    <th style="padding: 8px; text-align: right;">Esperado</th><th style="padding: 8px; text-align: right;">Contado</th>
                    <th style="padding: 8px; text-align: right;">Diferencia</th><th style="padding: 8px;">Cerrada por</th><th style="padding: 8px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($cajas as $c)
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 8px;">{{ $c->id }}</td>
                        <td style="padding: 8px;">{{ $c->user?->nombre ?? $c->user_id }}</td>
                        <td style="padding: 8px;">{{ $c->fecha_apertura?->format('d/m/Y H:i') }}</td>
                        <td style="padding: 8px; text-align: right;">{{ $c->monto_apertura }}</td>
                        <td style="padding: 8px;">{{ $c->estado }}@if($c->esHeredada()) <small title="Cerrada con el arqueo anterior a la Etapa 3">(heredada)</small>@endif</td>
                        <td style="padding: 8px;">{{ $c->fecha_cierre?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td style="padding: 8px; text-align: right;">{{ $c->efectivo_esperado ?? '—' }}</td>
                        <td style="padding: 8px; text-align: right;">{{ $c->efectivo_contado ?? $c->monto_cierre ?? '—' }}</td>
                        <td style="padding: 8px; text-align: right;">{{ $c->diferencia ?? '—' }}</td>
                        <td style="padding: 8px;">{{ $c->cerradoPor?->nombre ?? '—' }}@if($c->cierre_supervisado) <small>(supervisado)</small>@endif</td>
                        <td style="padding: 8px; white-space: nowrap;"><a href="{{ route('caja.show', $c) }}">Ver</a> · <a href="{{ route('caja.reporte', $c) }}">PDF</a></td>
                    </tr>
                @empty
                    <tr><td colspan="11" style="padding: 24px; text-align: center; color: var(--text-muted);">No hay cajas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top: 16px;">{{ $cajas->links() }}</div>
</div>
@endsection
