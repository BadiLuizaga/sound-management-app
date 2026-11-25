@extends('layouts.ferreteria')

@section('title', 'Detalle del pago')

@section('content')
<div class="card animate-fade-up" style="padding: 24px; margin-top: 20px;">
    <a href="{{ route('finanzas.admin.pagos.index') }}">← Volver a pagos</a>
    <h2 style="margin: 12px 0 4px;">Pago {{ $pago->uuid }}</h2>

    @include('finance::partials.mensajes')

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 16px 0;">
        <div><small>Estado del dinero</small><br><strong>{{ $pago->estado->value }}</strong></div>
        <div><small>Aplicación</small><br><strong>{{ $pago->estado_aplicacion->value }}</strong></div>
        <div><small>Importe</small><br><strong>Bs. {{ $pago->monto }}</strong></div>
        @if($pago->monto_proveedor)
            <div><small>Cobrado por el proveedor</small><br>{{ $pago->moneda_proveedor }} {{ $pago->monto_proveedor }} (tasa {{ $pago->tasa_aplicada }})</div>
        @endif
        <div><small>Método / canal</small><br>{{ $pago->metodoPago?->nombre }} · {{ $pago->canal }}</div>
        <div><small>Referencia</small><br>{{ $pago->referencia_externa ?? '—' }}</div>
        <div><small>Captura</small><br>{{ $pago->referencia_captura ?? '—' }}</div>
        <div><small>Venta</small><br>{{ $pago->nro_factura ?? '—' }}</div>
        <div><small>Registrado por</small><br>{{ $pago->registradoPor?->nombre ?? '—' }}</div>
        <div><small>Verificado por</small><br>{{ $pago->verificadoPor?->nombre ?? '—' }}</div>
    </div>

    @if($pago->motivo_revision)
        <div style="padding: 12px; border-radius: 10px; background: #FFFBEB; color: #92400E; margin-bottom: 16px;"><strong>En revisión:</strong> {{ $pago->motivo_revision }}</div>
    @endif

    {{-- Acciones: cada botón exige el mismo permiso que su ruta --}}
    <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px;">
        @if($pago->estado->value === 'pendiente_verificacion')
            @can('finance.payments.verify')
                <form method="POST" action="{{ route('finanzas.admin.pagos.verificar', $pago) }}">@csrf
                    <button type="submit" class="btn-save">Verificar ingreso</button>
                </form>
                <form method="POST" action="{{ route('finanzas.admin.pagos.rechazar', $pago) }}" style="display: flex; gap: 6px;">@csrf
                    <input type="text" name="motivo" placeholder="Motivo del rechazo" required maxlength="255">
                    <button type="submit" class="btn-action">Rechazar</button>
                </form>
            @endcan
        @endif

        @if($pago->estado_aplicacion->value === 'requiere_revision')
            @can('finance.payments.reconcile')
                <form method="POST" action="{{ route('finanzas.admin.pagos.reintentar', $pago) }}">@csrf
                    <button type="submit" class="btn-save">Reintentar aplicación</button>
                </form>
                <form method="POST" action="{{ route('finanzas.admin.pagos.aplicar-manual', $pago) }}" style="display: flex; gap: 6px;">@csrf
                    <input type="text" name="motivo" placeholder="Motivo del cierre manual" required maxlength="255">
                    <button type="submit" class="btn-action">Cerrar manualmente</button>
                </form>
            @endcan
        @endif
    </div>

    {{-- Historial de reembolsos (solo lectura): la operación de reembolsos ya no
         existe; se muestran los anteriores y los hechos desde PayPal. --}}
    @if($pago->reembolsos->isNotEmpty())
        <h3>Reembolsos registrados (historial)</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Fecha</th><th>Importe</th><th>Proveedor</th><th>Estado</th><th>Origen</th><th>Motivo</th></tr></thead>
            <tbody>
                @foreach($pago->reembolsos as $r)
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td>{{ $r->created_at?->format('d/m/Y H:i') }}</td>
                        <td>Bs. {{ $r->monto }}</td>
                        <td>{{ $r->monto_proveedor ? $r->moneda_proveedor . ' ' . $r->monto_proveedor : '—' }}</td>
                        <td>{{ $r->estado->value }}</td>
                        <td>{{ $r->origen }}</td>
                        <td>{{ $r->motivo }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>Historial</h3>
    <table style="width: 100%; border-collapse: collapse;">
        <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Fecha</th><th>Evento</th><th>Dinero</th><th>Aplicación</th></tr></thead>
        <tbody>
            @foreach($pago->eventos as $ev)
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td>{{ $ev->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td>{{ $ev->tipo }}</td>
                    <td>{{ $ev->estado_anterior }} → {{ $ev->estado_nuevo }}</td>
                    <td>{{ $ev->estado_aplicacion_anterior }} → {{ $ev->estado_aplicacion_nuevo }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
