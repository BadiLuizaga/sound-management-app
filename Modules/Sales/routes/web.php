<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\CartController;
use Modules\Sales\Http\Controllers\VentaController;
use Modules\Sales\Http\Controllers\PromocionController;
// Etapa 3: la caja la gestiona el módulo Finance (el CajaController heredado de
// Sales se conserva sin uso). Se mantienen los nombres de ruta caja.*.
use Modules\Finance\Http\Controllers\Admin\CajaController;

// ─────────────────────────────────────────────────────────
// PÚBLICAS — accesibles sin login
// ─────────────────────────────────────────────────────────
Route::get('/carrito', [CartController::class, 'index'])->name('carrito.index');
Route::post('/carrito/add', [CartController::class, 'add'])->name('carrito.add');
Route::post('/carrito/update', [CartController::class, 'update'])->name('carrito.update');
Route::get('/carrito/cotizacion', [CartController::class, 'generarCotizacion'])->name('cotizacion.generar');
Route::post('/carrito/remove', [CartController::class, 'remove'])->name('carrito.remove');
Route::post('/carrito/clear', [CartController::class, 'clear'])->name('carrito.clear');

Route::get('/ofertas', [PromocionController::class, 'indexPublic'])->name('promociones.public');
Route::post('/promociones/{id}/comprar', [CartController::class, 'addPromocion'])->name('promocion.comprar');

// ─────────────────────────────────────────────────────────
// AUTENTICADAS — requieren login
// ─────────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {

    // El checkout exige un pago verificado en el servidor (ver CartController@checkout).
    Route::post('/carrito/checkout', [CartController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('carrito.checkout');

    // Cotizaciones persistentes
    Route::post('/cotizaciones/guardar', [CartController::class, 'guardarCotizacion'])->name('cotizaciones.guardar');
    Route::get('/cotizaciones/guardadas', [CartController::class, 'verCotizacionesGuardadas'])->name('cotizaciones.guardadas');
    Route::post('/cotizaciones/cargar/{id}', [CartController::class, 'cargarCotizacion'])->name('cotizaciones.cargar');
    Route::delete('/cotizaciones/{id}', [CartController::class, 'eliminarCotizacion'])->name('cotizaciones.eliminar');

    // Punto de venta: solo personal con 'sales.operate'
    // (antes cualquier usuario autenticado, incluidos clientes, podía abrir el POS).
    Route::middleware('can:sales.operate')->group(function () {
        Route::get('/ventas', [VentaController::class, 'index'])->name('ventas.index');
        Route::get('/ventas/crear', [VentaController::class, 'create'])->name('ventas.create');
        Route::post('/ventas', [VentaController::class, 'store'])->name('ventas.store');
    });

    // Búsqueda y alta rápida de clientes (POS y alquileres): solo personal.
    // Antes cualquier usuario autenticado podía consultar nombre, teléfono y
    // email de otros clientes por su CI.
    Route::middleware(['can:customers.lookup', 'throttle:60,1'])->group(function () {
        Route::get('/api/clientes/buscar/{ci}', [VentaController::class, 'buscarCliente']);
        Route::post('/api/clientes/rapido', [VentaController::class, 'registrarCliente'])->name('clientes.rapido');
    });

    // Comprobante: personal de ventas o el propio cliente (control en el controlador).
    Route::get('/ventas/comprobante/{nro}', [VentaController::class, 'imprimir'])->name('ventas.comprobante');

    // Caja (Etapa 3): administrador, encargado y vendedor operan su propia caja.
    // Ver o cerrar cajas ajenas y los movimientos se comprueban en el controlador
    // con cash.view_all / cash.close_any / cash.movements.*.
    Route::middleware('can:cash.operate_own')->group(function () {
        Route::get('/caja', [CajaController::class, 'index'])->name('caja.index');
        Route::get('/caja/{caja}', [CajaController::class, 'show'])->whereNumber('caja')->name('caja.show');
        Route::post('/caja/apertura', [CajaController::class, 'apertura'])->middleware('throttle:10,1')->name('caja.apertura');
        Route::post('/caja/corte/{caja}', [CajaController::class, 'corte'])->whereNumber('caja')->middleware('throttle:20,1')->name('caja.corte');
        Route::get('/caja/reporte/{caja}', [CajaController::class, 'reporte'])->whereNumber('caja')->name('caja.reporte');
        Route::post('/caja/movimientos', [CajaController::class, 'movimiento'])->middleware('throttle:30,1')->name('caja.movimientos.store');
        Route::post('/caja/movimientos/{movimiento}/revertir', [CajaController::class, 'revertir'])
            ->middleware(['can:cash.movements.revert', 'throttle:20,1'])->name('caja.movimientos.revertir');
    });

    Route::middleware('admin')->group(function () {
        Route::get('/promociones', [PromocionController::class, 'index'])->name('admin.promociones.index');
        Route::get('/promociones/crear', [PromocionController::class, 'create'])->name('admin.promociones.create');
        Route::post('/promociones', [PromocionController::class, 'store'])->name('admin.promociones.store');
        Route::get('/promociones/{id}/editar', [PromocionController::class, 'edit'])->name('admin.promociones.edit');
        Route::put('/promociones/{id}', [PromocionController::class, 'update'])->name('admin.promociones.update');
        Route::delete('/promociones/{id}', [PromocionController::class, 'destroy'])->name('admin.promociones.destroy');
    });
});
