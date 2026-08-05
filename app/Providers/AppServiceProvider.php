<?php

namespace App\Providers;

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
    }

    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
