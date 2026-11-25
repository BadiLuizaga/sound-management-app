{{-- Desglose del arqueo. $a = ResultadoArqueo::toArray() (en vivo) o cajas.resumen (foto del cierre). --}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 16px;">
    <div><small>Fondo de apertura</small><br><strong>Bs. {{ $a['fondo'] }}</strong></div>
    <div><small>+ Cobros en efectivo</small><br><strong>Bs. {{ $a['efectivo_cobrado'] }}</strong></div>
    <div><small>− Reembolsos en efectivo</small><br><strong>Bs. {{ $a['reembolsos_efectivo'] }}</strong></div>
    <div><small>+ Ingresos manuales</small><br><strong>Bs. {{ $a['ingresos'] }}</strong></div>
    <div><small>− Egresos manuales</small><br><strong>Bs. {{ $a['egresos'] }}</strong></div>
    <div style="background: #F0FDFA; padding: 6px 10px; border-radius: 8px;"><small>= Efectivo esperado</small><br><strong style="font-size: 1.15em;">Bs. {{ $a['efectivo_esperado'] }}</strong></div>
    @isset($a['efectivo_contado'])
        <div><small>Efectivo contado</small><br><strong>Bs. {{ $a['efectivo_contado'] }}</strong></div>
        <div><small>Diferencia</small><br><strong style="color: {{ (float) $a['diferencia'] < 0 ? '#B91C1C' : ((float) $a['diferencia'] > 0 ? '#047857' : 'inherit') }};">Bs. {{ $a['diferencia'] }}</strong></div>
    @endisset
</div>

<h4 style="margin: 12px 0 6px;">Por método de pago <small style="font-weight: normal; color: var(--text-muted);">(solo el efectivo entra al arqueo)</small></h4>
<table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
    <thead><tr style="text-align: left; border-bottom: 2px solid #e2e8f0;"><th>Método</th><th style="text-align: right;">Recibido</th><th style="text-align: right;">Operaciones</th><th style="text-align: right;">Pendiente de verificar</th><th style="text-align: right;">Rechazado</th></tr></thead>
    <tbody>
        @foreach($a['metodos'] as $codigo => $m)
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td>{{ $m['nombre'] }} @if($codigo === 'PAYPAL')<small style="color: var(--text-muted);">(en línea, sin caja)</small>@endif</td>
                <td style="text-align: right;">Bs. {{ $m['recibido'] }}</td>
                <td style="text-align: right;">{{ $m['operaciones'] }}</td>
                <td style="text-align: right;">{{ $m['pendientes'] > 0 ? 'Bs. ' . $m['pendiente'] . ' (' . $m['pendientes'] . ')' : '—' }}</td>
                <td style="text-align: right;">{{ $m['rechazados'] > 0 ? 'Bs. ' . $m['rechazado'] . ' (' . $m['rechazados'] . ')' : '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if(!empty($a['reembolsos_por_medio']))
    <h4 style="margin: 12px 0 6px;">Reembolsos entregados desde esta caja</h4>
    <ul style="margin: 0 0 12px 18px;">
        @foreach($a['reembolsos_por_medio'] as $medio => $r)
            <li>{{ ucfirst($medio) }}: Bs. {{ $r['monto'] }} ({{ $r['cantidad'] }}) @if($medio !== 'efectivo')<small style="color: var(--text-muted);">— no resta efectivo</small>@endif</li>
        @endforeach
    </ul>
@endif

@if(!empty($a['pendientes']))
    <div style="padding: 10px 14px; border-radius: 10px; background: #FFFBEB; color: #92400E; margin-bottom: 12px;">
        <strong>Transferencias y QR pendientes de verificación</strong> (no son efectivo; se verifican en Finanzas › Pagos):
        <ul style="margin: 6px 0 0 18px;">
            @foreach($a['pendientes'] as $p)
                <li>{{ $p['metodo'] }} · Bs. {{ $p['monto'] }} · ref. {{ $p['referencia'] ?? '—' }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!empty($a['alquileres_fuera_del_arqueo']))
    <div style="padding: 10px 14px; border-radius: 10px; background: #FEF2F2; color: #991B1B; margin-bottom: 12px;">
        <strong>Alquileres heredados NO incluidos en el arqueo.</strong> No registran pagos en el sistema: cuéntalos aparte.
        <ul style="margin: 6px 0 0 18px;">
            @foreach($a['alquileres_fuera_del_arqueo'] as $al)
                <li>Alquiler #{{ $al['alquiler'] }} · {{ $al['metodo'] }} · estimado Bs. {{ $al['total_estimado'] }}@if($al['total_real'] !== null) · real Bs. {{ $al['total_real'] }}@endif · garantía Bs. {{ $al['garantia'] }} · {{ $al['estado'] }}</li>
            @endforeach
        </ul>
    </div>
@endif
