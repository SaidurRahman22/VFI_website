<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes  (mounted under /api by bootstrap/app.php)
|--------------------------------------------------------------------------
| Phase 0 baseline: a health check only. No product entity or business
| endpoint yet — see docs/phases/phase-0-platform-and-delivery-foundation.md.
*/

/**
 * GET /api/health
 * Liveness + dependency probe used by the deploy smoke test and the nginx
 * same-origin proof. Returns 200 when the app boots and the DB answers,
 * 503 otherwise. No auth, no secrets, safe to expose.
 */
Route::get('/health', function () {
    $checks = ['app' => 'ok'];
    $status = 200;

    try {
        DB::connection()->getPdo();
        DB::select('select 1');
        $checks['database'] = 'ok';
    } catch (\Throwable $e) {
        $checks['database'] = 'down';
        $status = 503;
    }

    return response()->json([
        'status'  => $status === 200 ? 'ok' : 'degraded',
        'service' => 'vfi-api',
        'checks'  => $checks,
        'time'    => now()->toIso8601String(),
    ], $status);
});

/* Admin scope (/api/admin/*) lives in routes/web.php — it is session-based and
   needs the web group's session + CSRF middleware. See there. */

/*
| Public contact-form intake (Phase 2 §7) — anonymous, stateless, rate-limited.
| POST /api/contact
*/
Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'store'])
    ->middleware('throttle:contact');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
