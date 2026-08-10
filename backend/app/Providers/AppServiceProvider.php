<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request; the BelongsToAgency scope + RLS binding
        // both read it. Set from the session only (P6), never a request param.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Server-side auth throttle (docs §8.1): per-account AND per-IP. Uses the
        // configured cache store (Redis in prod) — real counters, not a client
        // cooldown. Progressive account lockout is additionally handled on the
        // user row (User::registerFailedLogin).
        RateLimiter::for('admin-login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.$email),
            ];
        });
    }
}
