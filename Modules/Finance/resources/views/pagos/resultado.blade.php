@extends('layouts.ferreteria')

@section('title', 'Resultado del pago')

@section('content')
<div class="card animate-fade-up" style="max-width: 640px; margin: 40px auto; padding: 32px; text-align: center;">
    <h2 style="margin-bottom: 12px;">{{ $estado['mensaje'] }}</h2>

    <p style="color: var(--text-muted);">
        Referencia del pago: <code>{{ $pago->uuid }}</code><br>
        Importe: <strong>Bs. {{ $pago->monto }}</strong>
        @if($pago->monto_proveedor)
            ({{ $pago->moneda_proveedor }} {{ $pago->monto_proveedor }} con tasa {{ $pago->tasa_aplicada }})
        @endif
    </p>

    @if($pago->nro_factura)
        <p>Nota de venta N.º <strong>{{ $pago->nro_factura }}</strong>.
            <a href="{{ route('ventas.comprobante', $pago->nro_factura) }}">Descargar comprobante</a>
        </p>
    @endif

    @if($estado['estado'] === 'verificando')
        <p style="color: #92400E;">No vuelvas a pagar: el resultado se actualizará automáticamente.</p>
        <a href="{{ route('finanzas.pagos.resultado', $pago) }}" class="btn-save" style="display: inline-block; margin-top: 12px;">Actualizar estado</a>
    @endif

    <div style="margin-top: 24px;">
        <a href="{{ url('/') }}" class="btn-action">Volver al catálogo</a>
    </div>
</div>
@endsection
