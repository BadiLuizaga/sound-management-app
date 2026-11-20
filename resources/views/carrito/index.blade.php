@extends('layouts.ferreteria')

@section('title', 'Mi Carrito - Ferretería Guisella')

@section('content')
    <div class="animate-fade-up">
        <h1 style="margin: 0;">Mi Carrito de Compras</h1>
        <p class="subtitle">Revisa los productos seleccionados y procede al pago.</p>
    </div>

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">
            {{ session('error') }}
        </div>
    @endif

    @if(count($cartItems) > 0)
        <div class="card animate-fade-up" style="animation-delay: 0.1s;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-center">Precio Unitario</th>
                            <th class="text-center" style="width: 150px;">Cantidad</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-center" style="width: 80px;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($cartItems as $item)
                            <tr>
                                <td>
                                    <div style="font-weight: 700; color: var(--text-main);">{{ $item['nombre'] }}</div>
                                </td>
                                <td class="text-center">{{ number_format($item['precio'], 2) }} Bs.</td>
                                <td class="text-center">
                                    <form action="{{ route('carrito.update') }}" method="POST" class="ajax-cart-form" style="display: flex; gap: 8px; justify-content: center;">
                                        @csrf
                                        <input type="hidden" name="idproducto" value="{{ $item['idproducto'] }}">
                                        <input type="number" name="cantidad" value="{{ $item['cantidad'] }}" min="1" style="width: 70px; padding: 6px; text-align: center;" onchange="this.form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))">
                                        <button type="submit" class="btn-action" style="padding: 6px 10px;" title="Actualizar">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-right item-subtotal" style="font-weight: 800; color: var(--primary);">
                                    {{ number_format($item['subtotal'], 2) }} Bs.
                                </td>
                                <td class="text-center">
                                    <form action="{{ route('carrito.remove') }}" method="POST" class="ajax-cart-form ajax-remove">
                                        @csrf
                                        <input type="hidden" name="idproducto" value="{{ $item['idproducto'] }}">
                                        <button type="submit" class="btn-action danger" style="padding: 6px 10px;" title="Eliminar">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-top: 30px; flex-wrap: wrap; gap: 20px;">
                <div style="display: flex; gap: 12px;">
                    <form action="{{ route('carrito.clear') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn-action danger">Vaciar Carrito</button>
                    </form>
                    <a href="{{ url('/') }}" class="btn-action">Seguir Comprando</a>
                </div>

                <div style="background: var(--bg-light); padding: 24px; border-radius: var(--radius-md); min-width: 300px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-weight: 600;">
                        <span>Subtotal:</span>
                        <span>{{ number_format($total, 2) }} Bs.</span>
                    </div>

                    {{-- Descuentos de promociones/combos --}}
                    @if(!empty($discounts))
                        <div class="cart-discount-section">
                            @foreach($discounts as $discount)
                                <div class="cart-discount-item">
                                    <div class="cart-discount-label">
                                        <span class="cart-discount-name">🏷️ {{ $discount['nombre'] }}</span>
                                        <span class="cart-discount-desc">{{ $discount['descripcion'] }}</span>
                                    </div>
                                    <span class="cart-discount-amount">-{{ number_format($discount['monto'], 2) }} Bs.</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div style="display: flex; justify-content: space-between; font-size: 1.5rem; font-weight: 900; color: var(--text-main); margin-bottom: 24px; padding-top: 12px; border-top: 1px dashed var(--border); margin-top: 12px;">
                        <span>Total:</span>
                        <span class="cart-total" style="color: var(--primary);">{{ number_format($totalConDescuento, 2) }} Bs.</span>
                    </div>

                    <!-- Pago en línea (módulo Finance): orden y captura en el servidor -->
                    @auth
                        @if(($pagoEnLineaDisponible ?? false) && !empty($pagoEnLinea))
                            <div style="margin-top: 10px; padding: 12px; border-radius: 10px; background: #F0FDFA; color: #134E4A; font-size: 0.9rem;">
                                Total <strong>Bs. {{ $pagoEnLinea['monto_bob'] }}</strong> → se cobrará
                                <strong>{{ $pagoEnLinea['moneda_proveedor'] }} {{ $pagoEnLinea['monto_proveedor'] }}</strong>
                                (tipo de cambio {{ $pagoEnLinea['tasa'] }} Bs. por 1 {{ $pagoEnLinea['moneda_proveedor'] }} del {{ $pagoEnLinea['fecha_tasa'] }}).
                            </div>
                            <div id="paypal-button-container" style="width: 100%; margin-top: 15px;"></div>
                            <p id="pago-mensaje" style="margin-top: 10px; text-align: center; font-weight: 600; display: none;"></p>
                        @else
                            <p style="margin-top: 15px; text-align: center; color: var(--text-muted); font-weight: 600;">El pago en línea no está disponible por el momento.</p>
                        @endif
                        
                        <!-- Botón de Generar Cotización -->
                        <a href="{{ route('cotizacion.generar') }}" target="_blank" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 15px; background: white; color: #1F2937; border: 2px solid #E5E7EB; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 1.05rem; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.borderColor='#00AF9A'; this.style.color='#00AF9A';" onmouseout="this.style.borderColor='#E5E7EB'; this.style.color='#1F2937';">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            Generar Cotización (PDF)
                        </a>

                        <!-- Botón de Guardar Cotización en Cuenta -->
                        <form action="{{ route('cotizaciones.guardar') }}" method="POST" style="width: 100%; margin-top: 10px;">
                            @csrf
                            <button type="submit" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; background: linear-gradient(135deg, #6366F1, #8B5CF6); color: white; border: none; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 1.05rem; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(99, 102, 241, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(99, 102, 241, 0.3)';">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                Guardar Cotización en Cuenta
                            </button>
                        </form>

                        <!-- Botón de Ver Cotizaciones Guardadas -->
                        <a href="{{ route('cotizaciones.guardadas') }}" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 10px; background: white; color: #6366F1; border: 2px solid #6366F1; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 1.05rem; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='#6366F1'; this.style.color='white';" onmouseout="this.style.background='white'; this.style.color='#6366F1';">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            Ver Cotizaciones Guardadas
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn-save" style="display: block; text-align: center; width: 100%; margin-top: 15px; font-size: 1.1rem; padding: 16px; text-decoration: none;">Iniciar Sesión para Pagar</a>
                    @endauth
                </div>
            </div>
        </div>
    @else
        <div class="card animate-fade-up text-center" style="padding: 60px 20px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--text-light)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 20px;"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            <h2 style="color: var(--text-muted); margin-bottom: 16px;">Tu carrito está vacío</h2>
            <p style="color: var(--text-light); margin-bottom: 24px;">No has agregado ningún producto todavía.</p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="{{ url('/') }}" class="btn-save">Ir al Catálogo</a>
                @auth
                <a href="{{ route('cotizaciones.guardadas') }}" style="display: inline-flex; align-items: center; gap: 8px; background: white; color: #6366F1; border: 2px solid #6366F1; padding: 12px 24px; border-radius: 12px; font-weight: 700; font-size: 1rem; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='#6366F1'; this.style.color='white';" onmouseout="this.style.background='white'; this.style.color='#6366F1';">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    Ver Cotizaciones Guardadas
                </a>
                @endauth
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    @auth
        @if(count($cartItems) > 0 && ($pagoEnLineaDisponible ?? false) && !empty($pagoEnLinea))
        {{-- SDK con la moneda de cobro del proveedor; el importe lo fija el SERVIDOR al crear la orden --}}
        <script src="https://www.paypal.com/sdk/js?client-id={{ urlencode(config('services.paypal.client_id')) }}&currency={{ urlencode($pagoEnLinea['moneda_proveedor']) }}&intent=capture"></script>
        <script>
            (function () {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
                // Una clave por carga de página: si se reintenta, el servidor devuelve el mismo intento de pago
                const clave = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(16).slice(2) + '-pago');
                let uuidPago = null;
                const mensaje = document.getElementById('pago-mensaje');

                function mostrar(texto, error) {
                    mensaje.textContent = texto;
                    mensaje.style.display = 'block';
                    mensaje.style.color = error ? '#B91C1C' : '#065F46';
                }

                async function post(url, cuerpo) {
                    const r = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify(cuerpo || {})
                    });
                    const datos = await r.json().catch(() => ({}));
                    if (!r.ok) { throw new Error(datos.message || 'No se pudo procesar el pago.'); }
                    return datos;
                }

                paypal.Buttons({
                    // El servidor calcula el importe, congela la tasa y crea la orden
                    createOrder: async function () {
                        const datos = await post(@json(route('finanzas.paypal.orden')), { clave_idempotencia: clave });
                        uuidPago = datos.uuid;
                        return datos.order_id;
                    },
                    // El servidor captura y verifica; el navegador solo muestra el resultado
                    onApprove: async function () {
                        mostrar('Confirmando tu pago con PayPal, no cierres esta página…', false);
                        try {
                            const datos = await post(@json(url('/pagos')) + '/' + encodeURIComponent(uuidPago) + '/captura');
                            window.location.href = datos.resultado_url;
                        } catch (e) {
                            mostrar(e.message + ' Si PayPal te cobró, el pago está registrado y se confirmará automáticamente.', true);
                        }
                    },
                    onCancel: function () { mostrar('Pago cancelado. No se realizó ningún cobro.', true); },
                    onError: function (err) {
                        console.error('Error en el flujo de PayPal', err);
                        mostrar(err && err.message ? err.message : 'Ocurrió un problema con PayPal. No se realizó ningún cobro.', true);
                    }
                }).render('#paypal-button-container');
            })();
        </script>
        @endif
    @endauth
@endpush
