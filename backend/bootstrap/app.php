<?php

use App\Http\Middleware\StaffRlsRead;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Same-origin SPA cookie auth for the static frontend. The whole
        // Sanctum posture (Phase 1) depends on the site + API sharing one
        // origin — proven in Phase 0's nginx same-origin test.
        $middleware->statefulApi();

        // PHASE 0, step 9 — the empty-string landmine.
        // The frontend store API treats "" and [] as "keep the page's
        // built-in HTML" across ~32 pages. Laravel's default TrimStrings +
        // ConvertEmptyStringsToNull would silently turn those into null and
        // wipe that meaning. Exempt the (future) content/pages write routes
        // now, before any content code exists, so the seam is safe from day one.
        $keepEmptyStrings = fn (Request $request) => $request->is('api/admin/content*')
            || $request->is('api/admin/pages*')
            || $request->is('api/admin/settings*');
        $middleware->convertEmptyStringsToNull(except: [$keepEmptyStrings]);
        $middleware->trimStrings(except: [$keepEmptyStrings]);

        // Guests hitting the Filament panel (web) are sent to our TOTP-gated
        // login page — the panel has no Filament login form. (/api/* still
        // renders a JSON 401, not a redirect — see withExceptions below.)
        $middleware->redirectGuestsTo(fn () => '/admin-login.html');

        // Staff read across tenants past Postgres RLS. In the WEB group, not the
        // Filament panel group, because the panel's buttons act through
        // /livewire/update which the panel group does not cover. It gates itself
        // on an admin role, so partner and student requests are unaffected.
        $middleware->web(append: [StaffRlsRead::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
