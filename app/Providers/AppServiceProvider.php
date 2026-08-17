<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('sso-exchange', function (Request $request): Limit {
            $clientId = (string) ($request->getUser() ?: $request->input('client_id', 'unknown'));

            return Limit::perMinute(30)->by($clientId.'|'.$request->ip());
        });

        RateLimiter::for('sso-provision', function (Request $request): Limit {
            $clientId = (string) ($request->getUser() ?: $request->input('client_id', 'unknown'));

            return Limit::perMinute(300)->by($clientId.'|'.$request->ip());
        });

        RateLimiter::for('billing-usage', function (Request $request): Limit {
            $clientId = (string) ($request->getUser() ?: $request->input('client_id', 'unknown'));

            return Limit::perMinute(12)->by($clientId.'|'.$request->ip());
        });

        RateLimiter::for('billing-entitlement', function (Request $request): Limit {
            $clientId = (string) ($request->getUser() ?: $request->input('client_id', 'unknown'));

            return Limit::perMinute(120)->by($clientId.'|'.$request->ip());
        });
    }
}
