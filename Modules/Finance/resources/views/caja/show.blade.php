@extends('layouts.ferreteria')

@section('title', 'Caja #' . $caja->id)

@section('content')
<div class="card animate-fade-up" style="padding: 24px; margin-top: 20px;">
    <a href="{{ route('caja.index') }}">← Volver a caja</a>
    <h2 style="margin: 12px 0 4px;">Caja #{{ $caja->id }} — {{ $caja->estado }}@if($caja->esHeredada()) (heredada)@endif</h2>

    @include('finance::partials.mensajes')

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 12px 0 16px;">
        <div><small>Titular</small><br><strong>{{ $caja->user?->nombre ?? $caja->user_id }}</strong></div>
        <div><small>Apertura</small><br>{{ $caja->fecha_apertura?->format('d/m/Y H:i') }}</div>
        <div><small>Cierre</small><br>{{ $caja->fecha_cierre?->format('d/m/Y H:i') ?? '—' }}</div>
        <div><small>Cerrada por</small><br>{{ $caja->cerradoPor?->nombre ?? '—' }}@if($caja->cierre_supervisado) <strong>(cierre supervisado)</strong>@endif</div>
        @if($caja->observaciones)
            <div style="grid-column: 1 / -1;"><small>Observaciones del cierre</small><br>{{ $caja->observaciones }}</div>
        @endif
    </div>

    @if($arqueo)
        @include('finance::caja.partials.arqueo', ['a' => $arqueo])
    @else
        <div style="padding: 12px; border-radius: 10px; background: #F8FAFC; margin-bottom: 16px;">
            Caja cerrada con el arqueo anterior a la Etapa 3 (sin desglose por método). Sus importes se conservan sin recalcular:
            efectivo real Bs. {{ $caja->monto_cierre ?? '—' }}, diferencia Bs. {{ $caja->diferencia ?? '—' }}.
        </div>
    @endif

    <p><a href="{{ route('caja.reporte', $caja) }}">Descargar corte en PDF</a></p>

    {{-- Cierre: propio (cash.operate_own) o supervisado (cash.close_any), igual que la ruta --}}
    @if($caja->estaAbierta())
        @if(($esPropia && auth()->user()->can('cash.operate_own')) || (!$esPropia && auth()->user()->can('cash.close_any')))
            <form method="POST" action="{{ route('caja.corte', $caja) }}" style="display: flex; flex-direction: column; gap: 6px; max-width: 420px; margin-bottom: 24px; border: 1px solid #e2e8f0; padding: 12px; border-radius: 10px;">
                @csrf
                <input type="hidden" name="clave_cierre" value="{{ \Illuminate\Support\Str::uuid() }}">
                <strong>{{ $esPropia ? 'Cerrar mi caja' : 'Cierre supervisado' }}</strong>
                @unless($esPropia)<small>La caja es de {{ $caja->user?->nombre }}; quedarás registrado como responsable del cierre. Observación obligatoria.</small>@endunless
                <label>Efectivo contado (Bs.) <input type="text" name="efectivo_contado" required pattern="\d{1,9}(\.\d{1,2})?"></label>
                <textarea name="observaciones" maxlength="500" placeholder="Observaciones" @unless($esPropia) required @endunless></textarea>
                <button type="submit" class="btn-save">Cerrar caja</button>
            </form>
        @endif
    @endif

    <h3>Cobros de la caja</h3>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Fecha</th><th>Método</th><th style="text-align: right;">Importe</th><th>Dinero</th><th>Venta</th><th></th></tr></thead>
        <tbody>
            @forelse($pagos as $p)
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td>{{ $p->created_at?->format('d/m/Y H:i') }}</td><td>{{ $p->metodoPago?->nombre }}</td>
                    <td style="text-align: right;">{{ $p->monto }}</td><td>{{ $p->estado->value }}</td><td>{{ $p->nro_factura ?? '—' }}</td>
                    <td>@canany(['finance.payments.view_all', 'finance.payments.view_own'])<a href="{{ route('finanzas.admin.pagos.show', $p) }}">Ver</a>@endcanany</td>
                </tr>
            @empty
                <tr><td colspan="6" style="padding: 8px; color: var(--text-muted);">Sin cobros.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Reembolsos entregados desde la caja</h3>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Entregado</th><th>Medio</th><th style="text-align: right;">Importe</th><th>Estado</th><th>Entregado por</th></tr></thead>
        <tbody>
            @forelse($reembolsos as $r)
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td>{{ $r->entregado_at?->format('d/m/Y H:i') ?? '—' }}</td><td>{{ $r->medio_devolucion ?? '—' }}</td>
                    <td style="text-align: right;">{{ $r->monto }}</td><td>{{ $r->estado->value }}</td><td>{{ $r->entregadoPor?->nombre ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="padding: 8px; color: var(--text-muted);">Sin reembolsos.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Movimientos manuales</h3>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Fecha</th><th>Tipo</th><th style="text-align: right;">Importe</th><th>Motivo</th><th>Registrado por</th><th>Referencias</th><th></th></tr></thead>
        <tbody>
            @forelse($caja->movimientos as $m)
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td>{{ $m->created_at?->format('d/m/Y H:i') }}</td><td>{{ $m->tipo }}</td>
                    <td style="text-align: right;">{{ $m->monto }}</td><td>{{ $m->motivo }}</td><td>{{ $m->registradoPor?->nombre }}</td>
                    <td>
                        @if($m->revierte_id)Revierte el movimiento #{{ $m->revierte_id }}@endif
                        @if($m->caja_corregida_id) · corrige la caja <a href="{{ route('caja.show', $m->caja_corregida_id) }}">#{{ $m->caja_corregida_id }}</a>@endif
                        @if($m->reversion) Revertido por el movimiento #{{ $m->reversion->id }}@endif
                    </td>
                    <td>
                        @if(!$m->revierte_id && !$m->reversion)
                            @can('cash.movements.revert')
                                <form method="POST" action="{{ route('caja.movimientos.revertir', $m) }}" style="display: flex; gap: 4px;">@csrf
                                    <input type="hidden" name="clave_idempotencia" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <input type="text" name="motivo" placeholder="Motivo" required maxlength="255" style="width: 140px;">
                                    <button type="submit">Revertir</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="padding: 8px; color: var(--text-muted);">Sin movimientos.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($caja->correcciones->isNotEmpty())
        <h3>Correcciones registradas después del cierre</h3>
        <p style="color: var(--text-muted);">La foto del cierre no se modifica; estas correcciones se registraron en otras cajas.</p>
        <ul>
            @foreach($caja->correcciones as $m)
                <li>{{ $m->created_at?->format('d/m/Y H:i') }} · {{ $m->tipo }} Bs. {{ $m->monto }} en la caja <a href="{{ route('caja.show', $m->caja_id) }}">#{{ $m->caja_id }}</a> · {{ $m->motivo }} ({{ $m->registradoPor?->nombre }})</li>
            @endforeach
        </ul>
    @endif

    <h3>Historial</h3>
    <table style="width: 100%; border-collapse: collapse;">
        <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Fecha</th><th>Evento</th><th>Responsable</th><th>Detalle</th></tr></thead>
        <tbody>
            @forelse($caja->eventos as $ev)
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td>{{ $ev->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td>{{ str_replace('_', ' ', $ev->tipo) }}</td>
                    <td>{{ $ev->responsable?->nombre ?? '—' }}</td>
                    <td><small>{{ collect($ev->datos ?? [])->filter(fn ($v) => $v !== null && !is_array($v))->map(fn ($v, $k) => $k . ': ' . (is_bool($v) ? ($v ? 'sí' : 'no') : $v))->implode(' · ') }}</small></td>
                </tr>
            @empty
                <tr><td colspan="4" style="padding: 8px; color: var(--text-muted);">Sin eventos registrados (caja anterior a la Etapa 3).</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
