<?php

namespace App\Providers;

use App\Models\Content\Blog;
use App\Models\Content\Event;
use App\Models\Content\NewsItem;
use App\Models\Content\Photo;
use App\Models\Content\PpDoc;
use App\Models\Content\PpEmail;
use App\Models\Content\PpManager;
use App\Models\Content\PpNotif;
use App\Models\Content\PpQuicklink;
use App\Models\Content\PpUpdate;
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

        // Phase 8 — public university directory reads: generous per-IP cap.
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(60)->by('ip:'.$request->ip()));

        // Phase 8 — program search: per-signed-in-partner cap (infra limiter; the
        // commercial quota is separate). Falls back to IP for safety.
        RateLimiter::for('program-search', fn (Request $request) => Limit::perMinute(
            (int) config('catalogue.search_rate_per_minute', 40)
        )->by($request->user()?->getAuthIdentifier() ? 'u:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()));

        // Phase 3E — content policy on all 10 collection models (Filament enforces it).
        foreach ([
            Event::class, Blog::class,
            NewsItem::class, Photo::class,
            PpManager::class, PpUpdate::class,
            PpQuicklink::class, PpDoc::class,
            PpEmail::class, PpNotif::class,
        ] as $model) {
            Gate::policy($model, ContentPolicy::class);
        }
    }
}
