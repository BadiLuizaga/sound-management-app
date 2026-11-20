<?php

namespace Modules\Finance\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Finance';

    public function boot(): void
    {
        parent::boot();
    }

    public function map(): void
    {
        $this->mapWebRoutes();
    }

    /**
     * Rutas web (sesión + CSRF). El webhook de PayPal está excluido de CSRF
     * de forma explícita en bootstrap/app.php.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }
}
