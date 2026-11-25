<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\Admin\PagoController;
use Modules\Finance\Http\Controllers\Admin\ReporteFinancieroController;
use Modules\Finance\Http\Controllers\Admin\TipoCambioController;
use Modules\Finance\Http\Controllers\PagoEnLineaController;
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
