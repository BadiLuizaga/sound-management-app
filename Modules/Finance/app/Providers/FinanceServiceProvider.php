<?php

namespace Modules\Finance\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Finance\Console\ConciliarPagos;
use Modules\Finance\Gateways\PayPal\PayPalGateway;
use Modules\Finance\Gateways\PaymentGateway;
use Nwidart\Modules\Support\ModuleServiceProvider;

class FinanceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Finance';

    protected string $nameLower = 'finance';

    protected array $commands = [
        ConciliarPagos::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // Proveedor de pagos en línea (PayPal). En las pruebas se simula con Http::fake().
        $this->app->bind(PaymentGateway::class, PayPalGateway::class);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('finanzas:conciliar')->everyFiveMinutes()->withoutOverlapping();
    }
}
