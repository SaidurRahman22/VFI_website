<?php

namespace App\Providers;

use App\Policies\ContentPolicy;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        // Public contact form (Phase 2 §7.2): per-IP and per-email caps.
        RateLimiter::for('contact', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinutes(10, 3)->by('email:'.$email),
            ];
        });

        // Phase 4 — student auth throttles (docs §5). Server-side, per-email AND
        // per-IP, independent of the client cooldowns (which reset on reload).
        RateLimiter::for('student-register', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinutes(60, 5)->by('email:'.$email),
            ];
        });

        // OTP resend: hard cap of 3 sends/hour/email (+ per-IP); the service also
        // enforces a 30s minimum interval on the same flow.
        RateLimiter::for('otp-send', function (Request $request) {
            $flow = (string) $request->input('flow_id');

            return [
                Limit::perHour(20)->by('ip:'.$request->ip()),
                Limit::perHour(3)->by('flow:'.$flow),
            ];
        });

        // OTP verify: per-IP ceiling on top of the per-flow 5-attempt cap.
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(20)->by('ip:'.$request->ip()));

        // Student sign-in (docs §1.4): mirror of admin-login.
        RateLimiter::for('student-login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.$email),
            ];
        });

        // Forgot-password: per-email AND per-IP; low hourly cap ends email amplification.
        RateLimiter::for('password-forgot', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perHour(5)->by('email:'.$email),
            ];
        });

        // Phase 6 — partner registration + sign-in (mirror the student throttles).
        RateLimiter::for('partner-register', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinutes(60, 5)->by('email:'.$email),
            ];
        });

        RateLimiter::for('partner-login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.$email),
            ];
        });

        // Phase 7 — public QR-referral resolver: per-slug + per-IP cap.
        RateLimiter::for('referral-resolve', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(20)->by('slug:'.$request->route('slug')),
        ]);

        // Phase 3E — content policy on all 10 collection models (Filament enforces it).
        foreach ([
            \App\Models\Content\Event::class, \App\Models\Content\Blog::class,
            \App\Models\Content\NewsItem::class, \App\Models\Content\Photo::class,
            \App\Models\Content\PpManager::class, \App\Models\Content\PpUpdate::class,
            \App\Models\Content\PpQuicklink::class, \App\Models\Content\PpDoc::class,
            \App\Models\Content\PpEmail::class, \App\Models\Content\PpNotif::class,
        ] as $model) {
            Gate::policy($model, ContentPolicy::class);
        }
    }
}
