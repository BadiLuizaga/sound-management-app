<?php

namespace App\Providers;

use App\Support\AccessControl;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una instancia por petición: cachea los roles del usuario autenticado
        // y evita una consulta por cada @can / Gate::allows.
        $this->app->scoped(AccessControl::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Gates definidos a partir de config/access.php.
        // Incluye los históricos 'admin', 'almacenero' y 'cliente' con la misma
        // base que antes (rol activo en estadoRol / tipoPersona), más las
        // habilidades nuevas por módulo (inventory.manage, sales.operate, ...).
        foreach (array_keys((array) config('access.abilities', [])) as $ability) {
            Gate::define($ability, function ($user) use ($ability) {
                return $this->app->make(AccessControl::class)->allows($user, $ability);
            });
        }
    }
}
