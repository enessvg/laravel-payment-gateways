<?php

namespace App\Providers;

use App\Interfaces\PaymentInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentInterface::class, function (Application $app): PaymentInterface {
            $driver = config('payment.default');
            $gateway = config("payment.gateways.{$driver}.driver");

            if (! is_string($gateway) || ! is_a($gateway, PaymentInterface::class, true)) {
                throw new LogicException("Payment gateway [{$driver}] is not configured correctly.");
            }

            return $app->make($gateway);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DevCommands::artisan('serve --host=localhost --port=9000', 'server');
    }
}
