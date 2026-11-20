<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;
use Modules\Audit\Http\Controllers\IaReportController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('admin')->group(function () {
        Route::get('/bitacora', [AuditController::class, 'index'])->name('bitacora.index');

        // Reportes por IA: solo administradores con 'reports.ai'.
        // Antes estaban abiertos a cualquier usuario autenticado (incluidos clientes)
        // y ejecutaban SQL generado por el modelo sin restricciones.
        Route::middleware('can:reports.ai')->group(function () {
            Route::get('/reportes-ia', [IaReportController::class, 'index'])->name('reportes-ia.index');
            Route::post('/reportes-ia/consultar', [IaReportController::class, 'consultar'])
                ->middleware('throttle:10,1')
                ->name('reportes-ia.consultar');
        });
    });
});
