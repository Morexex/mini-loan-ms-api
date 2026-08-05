<?php

namespace App\Providers;

use App\Domain\Reconciliation\AllocatingReconciliationService;
use App\Domain\Reconciliation\ReconciliationService;
use App\Infrastructure\Daraja\DarajaGateway;
use App\Infrastructure\Daraja\FakeDarajaGateway;
use App\Infrastructure\Daraja\SandboxDarajaGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DarajaGateway::class, function () {
            if ($this->app->environment('testing') || (bool) config('daraja.fake')) {
                return new FakeDarajaGateway;
            }

            return new SandboxDarajaGateway;
        });

        $this->app->bind(ReconciliationService::class, AllocatingReconciliationService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
