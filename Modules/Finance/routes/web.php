<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\Admin\PagoController;
use Modules\Finance\Http\Controllers\Admin\ReporteFinancieroController;
use Modules\Finance\Http\Controllers\Admin\TipoCambioController;
use Modules\Finance\Http\Controllers\PagoEnLineaController;
use Modules\Finance\Http\Controllers\PagoReservaController;
use Modules\Finance\Http\Controllers\PayPalWebhookController;

// ─────────────────────────────────────────────────────────
// Pago en línea (cliente autenticado; solo sus propios pagos)
// ─────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/pagos/paypal/ordenes', [PagoEnLineaController::class, 'crearOrden'])
        ->middleware('throttle:10,1')
        ->name('finanzas.paypal.orden');
    Route::post('/pagos/{pago}/captura', [PagoEnLineaController::class, 'capturar'])
        ->middleware('throttle:10,1')
        ->name('finanzas.paypal.captura');
    Route::get('/pagos/{pago}/estado', [PagoEnLineaController::class, 'estado'])
        ->name('finanzas.pagos.estado');
    Route::get('/pagos/{pago}/resultado', [PagoEnLineaController::class, 'resultado'])
        ->name('finanzas.pagos.resultado');

    // ─────────────────────────────────────────────────────
    // Pagos de reservas (Etapa 4 — paso 4): anticipos y saldos
    // ─────────────────────────────────────────────────────
    // Estado de cuenta: titular o personal con acceso a reservas (control en el controlador)
    Route::get('/reservas/{reserva}/estado-cuenta', [PagoReservaController::class, 'estadoCuenta'])
        ->name('finanzas.reservas.estado-cuenta');
    // En línea: solo el titular; la captura usa finanzas.paypal.captura
    Route::post('/reservas/{reserva}/pagos/paypal', [PagoReservaController::class, 'pagarEnLinea'])
        ->middleware('throttle:10,1')
        ->name('finanzas.reservas.paypal.orden');
    // En caja: exige la caja abierta del cajero
    Route::post('/finanzas/reservas/{reserva}/cobros', [PagoReservaController::class, 'cobrarEnCaja'])
        ->middleware('can:finance.payments.register')
        ->name('finanzas.reservas.cobros.store');

    // ─────────────────────────────────────────────────────
    // Administración financiera (permisos finance.* por ruta)
    // ─────────────────────────────────────────────────────
    Route::prefix('finanzas')->name('finanzas.admin.')->group(function () {
        // Listado y detalle: view_all o view_own (se comprueba en el controlador)
        Route::get('/pagos', [PagoController::class, 'index'])->name('pagos.index');
        Route::get('/pagos/{pago}', [PagoController::class, 'show'])->name('pagos.show');

        Route::post('/pagos/{pago}/verificar', [PagoController::class, 'verificar'])
            ->middleware('can:finance.payments.verify')->name('pagos.verificar');
        Route::post('/pagos/{pago}/rechazar', [PagoController::class, 'rechazar'])
            ->middleware('can:finance.payments.verify')->name('pagos.rechazar');
        Route::post('/pagos/{pago}/reintentar', [PagoController::class, 'reintentar'])
            ->middleware('can:finance.payments.reconcile')->name('pagos.reintentar');
        Route::post('/pagos/{pago}/aplicar-manual', [PagoController::class, 'aplicarManual'])
            ->middleware('can:finance.payments.reconcile')->name('pagos.aplicar-manual');

        // Reportes financieros (Etapa 3, D8: pantalla y CSV)
        Route::middleware(['can:finance.reports.view', 'throttle:60,1'])->group(function () {
            Route::get('/reportes', [ReporteFinancieroController::class, 'index'])->name('reportes.index');
            Route::get('/reportes/csv', [ReporteFinancieroController::class, 'csv'])->name('reportes.csv');
        });

        Route::middleware('can:finance.exchange.manage')->group(function () {
            Route::get('/tipos-cambio', [TipoCambioController::class, 'index'])->name('tipos-cambio.index');
            Route::post('/tipos-cambio', [TipoCambioController::class, 'store'])->name('tipos-cambio.store');
            Route::put('/tipos-cambio/{tipoCambio}', [TipoCambioController::class, 'update'])->name('tipos-cambio.update');
        });
    });
});

// ─────────────────────────────────────────────────────────
// Webhook de PayPal: público, firma verificada, sin CSRF
// (excepción declarada solo para esta ruta en bootstrap/app.php)
// ─────────────────────────────────────────────────────────
Route::post('/webhooks/paypal', PayPalWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('finanzas.webhooks.paypal');
