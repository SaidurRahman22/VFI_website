<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Admin scope  (/api/admin/*)  — Phase 1: Identity Spine & Admin Lockdown
|--------------------------------------------------------------------------
| These live in the WEB group deliberately: the two-step login (password →
| mandatory TOTP) is session-based and same-origin, so it wants the session +
| cookie + CSRF (VerifyCsrfToken) middleware the web group provides. Nothing
| under EnsureAdmin is reachable without a completed admin session + TOTP.
| Auth steps are rate-limited server-side (docs §8.1).
*/
Route::prefix('api/admin')->group(function () {
    Route::middleware('throttle:admin-login')->group(function () {
        Route::post('login', [AdminAuthController::class, 'login']);
        Route::post('login/totp', [AdminAuthController::class, 'totp']);
        Route::post('mfa/enroll', [AdminAuthController::class, 'enroll']);
        Route::post('mfa/confirm', [AdminAuthController::class, 'confirmEnroll']);
    });

    Route::middleware(['auth:web', EnsureAdmin::class])->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);

        // Phase 3C — override-singleton editor (optimistic concurrency).
        Route::get('content/singleton/{key}', [\App\Http\Controllers\Admin\AdminContentController::class, 'show']);
        Route::put('content/singleton/{key}', [\App\Http\Controllers\Admin\AdminContentController::class, 'update']);

        // Phase 3D — page-visibility (owner-only, allow-listed, audited).
        Route::get('pages', [\App\Http\Controllers\Admin\AdminPageController::class, 'index']);
        Route::put('pages/{file}', [\App\Http\Controllers\Admin\AdminPageController::class, 'toggle']);

        // Phase 3F — image upload + media-slot registry (content_editor/owner).
        Route::post('media', [\App\Http\Controllers\Admin\AdminMediaController::class, 'upload']);
        Route::put('media/slot/{key}', [\App\Http\Controllers\Admin\AdminMediaController::class, 'setSlot']);

        // Phase 3G — backup export / guarded restore (owner-only, snapshotted).
        Route::get('backup/export', [\App\Http\Controllers\Admin\AdminBackupController::class, 'export']);
        Route::post('backup/import', [\App\Http\Controllers\Admin\AdminBackupController::class, 'import']);
    });
});

/*
|--------------------------------------------------------------------------
| Student identity  (/api/*)  — Phase 4: Registration, Sign-in, OTP, Reset
|--------------------------------------------------------------------------
| In the WEB group (like admin auth): session-cookie + CSRF, same-origin. The
| static student auth pages (login/verify/forgot/reset) call these through
| js/api.js. register/verify/resend/context are stateless (flow_id-keyed);
| login establishes the student session; me/logout require the student scope.
*/
Route::prefix('api')->group(function () {
    $sa = \App\Http\Controllers\Auth\StudentAuthController::class;

    Route::post('register', [$sa, 'register'])->middleware('throttle:student-register');
    Route::post('verify', [$sa, 'verify'])->middleware('throttle:otp-verify');
    Route::post('verify/resend', [$sa, 'resend'])->middleware('throttle:otp-send');
    Route::get('verify/context', [$sa, 'verifyContext'])->middleware('throttle:otp-verify');

    Route::post('login', [$sa, 'login'])->middleware('throttle:student-login');

    Route::middleware(['auth:web', \App\Http\Middleware\EnsureStudent::class])->group(function () use ($sa) {
        Route::get('student/me', [$sa, 'me']);
        Route::post('student/logout', [$sa, 'logout']);
    });
});
