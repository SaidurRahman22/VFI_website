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

    // Password reset (P4-D): request is enumeration-safe + throttled; submit
    // consumes the single-use token and revokes all sessions.
    Route::post('password/reset', [$sa, 'forgotRequest'])->middleware('throttle:password-forgot');
    Route::post('password/reset/submit', [$sa, 'resetSubmit'])->middleware('throttle:otp-verify');

    Route::middleware(['auth:web', \App\Http\Middleware\EnsureStudent::class])->group(function () use ($sa) {
        Route::get('student/me', [$sa, 'me']);
        Route::post('student/logout', [$sa, 'logout']);

        // Phase 5 — student portal, all IMPLICIT-SELF (no id in any path/query).
        $profile = \App\Http\Controllers\Me\ProfileController::class;
        Route::get('me', [$profile, 'me']);
        Route::get('me/profile', [$profile, 'show']);
        Route::get('me/completeness', [$profile, 'completeness']);
        Route::put('me/profile/personal', [$profile, 'personal']);
        Route::put('me/profile/address', [$profile, 'address']);
        Route::put('me/qualifications', [$profile, 'qualifications']);
        Route::put('me/test_scores', [$profile, 'testScores']);
        Route::put('me/preferences', [$profile, 'preferences']);

        // Phase 5C/5D — document checklist + scan-gated upload pipeline.
        $docs = \App\Http\Controllers\Me\DocumentController::class;
        Route::get('me/documents', [$docs, 'index']);
        Route::get('me/documents/{type}/download', [$docs, 'download']);   // mint single-use URL
        Route::delete('me/documents/{type}', [$docs, 'destroy']);
        // Upload is gated on email verification (must_verify, docs §5.5).
        Route::post('me/documents/{type}', [$docs, 'store'])
            ->middleware(\App\Http\Middleware\EnsureVerifiedStudent::class);

        // Phase 5E — read-only application tracking.
        Route::get('me/tracking', [\App\Http\Controllers\Me\TrackingController::class, 'index']);
    });

    // PUBLIC single-use blob stream — the opaque token IS the capability (no
    // session needed), so it lives outside the student-scoped group. Throttled.
    Route::get('documents/dl/{token}', [\App\Http\Controllers\Me\DocumentController::class, 'stream'])
        ->middleware('throttle:otp-verify');

    /*
    | Partner console (Phase 6) — session-cookie + tenant-bound. EnsurePartner
    | resolves the agency from the SESSION only and pushes it into both the
    | Eloquent scope and Postgres RLS. Sign-in / register land in P6-C..E.
    */
    // Partner registration + OTP (public, throttled). Creates a reviewable
    // application; the email-change is bound to the server-side flow_id (P6-D).
    $pa = \App\Http\Controllers\Partner\PartnerAuthController::class;
    Route::post('partner/register', [$pa, 'register'])->middleware('throttle:partner-register');
    Route::post('partner/email/verify', [$pa, 'verify'])->middleware('throttle:otp-verify');
    Route::post('partner/email/code', [$pa, 'resend'])->middleware('throttle:otp-send');
    Route::post('partner/email/change', [$pa, 'emailChange'])->middleware('throttle:otp-send');
    Route::get('partner/verify/context', [$pa, 'verifyContext'])->middleware('throttle:otp-verify');

    // Partner sign-in + password reset (public, throttled). A successful reset
    // revokes every session the user holds across all agencies.
    Route::post('partner/signin', [$pa, 'signin'])->middleware('throttle:partner-login');
    Route::post('partner/password/forgot', [$pa, 'forgotRequest'])->middleware('throttle:password-forgot');
    Route::post('partner/password/reset/submit', [$pa, 'resetSubmit'])->middleware('throttle:otp-verify');
    Route::post('partner/logout', [$pa, 'logout'])->middleware('auth:web');

    Route::middleware(['auth:web', \App\Http\Middleware\EnsurePartner::class])->group(function () {
        $console = \App\Http\Controllers\Partner\PartnerConsoleController::class;
        Route::get('partner/me', [$console, 'me']);
        Route::get('partner/members', [$console, 'members']);

        // Phase 7 — console data surfaces (all tenant-scoped).
        $students = \App\Http\Controllers\Partner\PartnerStudentController::class;
        Route::get('partner/students', [$students, 'index']);
        Route::post('partner/students', [$students, 'store']);

        $apps = \App\Http\Controllers\Partner\PartnerApplicationController::class;
        Route::get('partner/applications', [$apps, 'index']);
        Route::post('partner/applications', [$apps, 'store']);
        Route::get('partner/dashboard/kpis', [$apps, 'kpis']);
        Route::get('partner/dashboard/deadlines', [$apps, 'deadlines']);

        $enq = \App\Http\Controllers\Partner\PartnerEnquiryController::class;
        Route::get('partner/enquiries', [$enq, 'index']);
        Route::post('partner/enquiries', [$enq, 'store']);
        Route::get('partner/enquiries/documents/{doc}/download', [$enq, 'download']);

        $ref = \App\Http\Controllers\Partner\PartnerReferralController::class;
        Route::get('partner/referral-link', [$ref, 'show']);
        Route::post('partner/referral-link/regenerate', [$ref, 'regenerate']);

        $notif = \App\Http\Controllers\Partner\PartnerNotificationController::class;
        Route::get('partner/notifications', [$notif, 'index']);
        Route::post('partner/notifications/read', [$notif, 'read']);

        Route::get('partner/resources', [\App\Http\Controllers\Partner\PartnerResourceController::class, 'index']);

        // Phase 8 — program search + detail over the flat catalogue index
        // (public reference data, but console-only + per-partner rate-limited).
        $programs = \App\Http\Controllers\Partner\PartnerProgramController::class;
        Route::get('partner/programs/search', [$programs, 'search'])->middleware('throttle:program-search');
        Route::get('partner/programs/{program}', [$programs, 'show'])->whereNumber('program')->middleware('throttle:program-search');
    });

    // PUBLIC referral resolver for the QR-registration landing (rate-limited).
    Route::get('referral/{slug}', [\App\Http\Controllers\Partner\PublicReferralController::class, 'resolve'])
        ->middleware('throttle:referral-resolve');

    // PUBLIC single-use enquiry-doc stream (opaque token = capability). Throttled.
    Route::get('partner/documents/dl/{token}', [\App\Http\Controllers\Partner\PartnerEnquiryController::class, 'stream'])
        ->middleware('throttle:otp-verify');
});
