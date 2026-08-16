<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContentBundleController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PublicUniversityController;
use App\Http\Controllers\TaxonomyController;
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
    } catch (Throwable $e) {
        $checks['database'] = 'down';
        $status = 503;
    }

    return response()->json([
        'status' => $status === 200 ? 'ok' : 'degraded',
        'service' => 'vfi-api',
        'checks' => $checks,
        'time' => now()->toIso8601String(),
    ], $status);
});

/* Admin scope (/api/admin/*) lives in routes/web.php — it is session-based and
   needs the web group's session + CSRF middleware. See there. */

/*
| Public contact-form intake (Phase 2 §7) — anonymous, stateless, rate-limited.
| POST /api/contact
*/
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact');

/*
| Public newsletter subscribe — the footer box on ~30 pages. Reuses the contact
| throttle: same shape of anonymous public write, same abuse profile.
*/
Route::post('/newsletter', [NewsletterController::class, 'store'])
    ->middleware('throttle:contact');

/*
| Public content read path (Phase 2C) — GET-only, ETag-cacheable, no cookies.
| GET /api/content/bundle  → the full content object for window.VFI_BOOTSTRAP.
*/
Route::get('/content/bundle', [ContentBundleController::class, 'bundle']);
// Classic-script bootstrap: sets window.VFI_BOOTSTRAP before store.js.
Route::get('/content/bootstrap.js', [ContentBundleController::class, 'bootstrap']);

// Phase 8 — the single served taxonomy (public reference data; kills the five
// divergent hardcoded option lists across the console/search pages).
Route::get('/taxonomy', [TaxonomyController::class, 'index']);

// Phase 8 — public student-facing university directory (no auth; public
// reference data). List + country facet + detail. Per-IP rate-limited.
Route::middleware('throttle:public-read')->group(function () {
    $uni = PublicUniversityController::class;
    Route::get('/universities', [$uni, 'index']);
    Route::get('/universities/meta', [$uni, 'meta']);
    Route::get('/universities/{institution}', [$uni, 'show'])->whereNumber('institution');
});

/* Student identity (Phase 4) lives in routes/web.php — like admin auth it is
   session-cookie based and same-origin, so it wants the web group's session +
   cookie + CSRF middleware unconditionally (the api group only attaches a
   session for Origin-matched stateful requests). See there. */

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
