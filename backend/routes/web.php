<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminBackupController;
use App\Http\Controllers\Admin\AdminContentController;
use App\Http\Controllers\Admin\AdminMediaController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\GdprExportController;
use App\Http\Controllers\Admin\StaffDocumentController;
use App\Http\Controllers\Auth\StudentAuthController;
use App\Http\Controllers\Me\DocumentController;
use App\Http\Controllers\Me\ProfileController;
use App\Http\Controllers\Me\TrackingController;
use App\Http\Controllers\Partner\PartnerApplicationController;
use App\Http\Controllers\Partner\PartnerAuthController;
use App\Http\Controllers\Partner\PartnerConsoleController;
use App\Http\Controllers\Partner\PartnerEnquiryController;
use App\Http\Controllers\Partner\PartnerNotificationController;
use App\Http\Controllers\Partner\PartnerProgramController;
use App\Http\Controllers\Partner\PartnerReferralController;
use App\Http\Controllers\Partner\PartnerResourceController;
use App\Http\Controllers\Partner\PartnerShortlistController;
use App\Http\Controllers\Partner\PartnerStudentController;
use App\Http\Controllers\Partner\PartnerStudentDocumentController;
use App\Http\Controllers\Partner\PublicReferralController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePartner;
use App\Http\Middleware\EnsureStudent;
use App\Http\Middleware\EnsureVerifiedStudent;
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
        Route::get('content/singleton/{key}', [AdminContentController::class, 'show']);
        Route::put('content/singleton/{key}', [AdminContentController::class, 'update']);

        // Phase 3D — page-visibility (owner-only, allow-listed, audited).
        Route::get('pages', [AdminPageController::class, 'index']);
        Route::put('pages/{file}', [AdminPageController::class, 'toggle']);

        // Phase 3F — image upload + media-slot registry (content_editor/owner).
        Route::post('media', [AdminMediaController::class, 'upload']);
        Route::put('media/slot/{key}', [AdminMediaController::class, 'setSlot']);

        // Phase 3G — backup export / guarded restore (owner-only, snapshotted).
        Route::get('backup/export', [AdminBackupController::class, 'export']);
        Route::post('backup/import', [AdminBackupController::class, 'import']);
    });
});

/*
| Phase 9A — staff document review file access. A browser link (not JSON) opened
| from the /manage review queue, so it sits outside the api/admin prefix but
| behind the SAME gate: a completed admin session with TOTP. Every open is
| written to document_access_log.
*/
Route::middleware(['auth:web', EnsureAdmin::class])->group(function () {
    Route::get('manage-files/documents/{document}', [StaffDocumentController::class, 'download'])
        ->whereNumber('document')
        ->name('staff.documents.download');

    // Phase 9B — the GDPR subject-access bundle. Same gate for the same reason:
    // this streams somebody's entire personal record, and every fetch is audited.
    Route::get('manage-files/gdpr-export/{record}', [GdprExportController::class, 'download'])
        ->whereNumber('record')
        ->name('admin.gdpr.export.download');
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
    $sa = StudentAuthController::class;

    Route::post('register', [$sa, 'register'])->middleware('throttle:student-register');
    Route::post('verify', [$sa, 'verify'])->middleware('throttle:otp-verify');
    Route::post('verify/resend', [$sa, 'resend'])->middleware('throttle:otp-send');
    Route::get('verify/context', [$sa, 'verifyContext'])->middleware('throttle:otp-verify');

    Route::post('login', [$sa, 'login'])->middleware('throttle:student-login');

    // Password reset (P4-D): request is enumeration-safe + throttled; submit
    // consumes the single-use token and revokes all sessions.
    Route::post('password/reset', [$sa, 'forgotRequest'])->middleware('throttle:password-forgot');
    Route::post('password/reset/submit', [$sa, 'resetSubmit'])->middleware('throttle:otp-verify');

    Route::middleware(['auth:web', EnsureStudent::class])->group(function () use ($sa) {
        Route::get('student/me', [$sa, 'me']);
        Route::post('student/logout', [$sa, 'logout']);

        // Phase 5 — student portal, all IMPLICIT-SELF (no id in any path/query).
        $profile = ProfileController::class;
        Route::get('me', [$profile, 'me']);
        Route::get('me/profile', [$profile, 'show']);
        Route::get('me/completeness', [$profile, 'completeness']);
        Route::put('me/profile/personal', [$profile, 'personal']);
        Route::put('me/profile/address', [$profile, 'address']);
        Route::put('me/qualifications', [$profile, 'qualifications']);
        Route::put('me/test_scores', [$profile, 'testScores']);
        Route::put('me/preferences', [$profile, 'preferences']);

        // Phase 5C/5D — document checklist + scan-gated upload pipeline.
        $docs = DocumentController::class;
        Route::get('me/documents', [$docs, 'index']);
        Route::get('me/documents/{type}/download', [$docs, 'download']);   // mint single-use URL
        Route::delete('me/documents/{type}', [$docs, 'destroy']);
        // Upload is gated on email verification (must_verify, docs §5.5).
        Route::post('me/documents/{type}', [$docs, 'store'])
            ->middleware(EnsureVerifiedStudent::class);

        // Phase 5E — read-only application tracking.
        Route::get('me/tracking', [TrackingController::class, 'index']);
    });

    // PUBLIC single-use blob stream — the opaque token IS the capability (no
    // session needed), so it lives outside the student-scoped group. Throttled.
    Route::get('documents/dl/{token}', [DocumentController::class, 'stream'])
        ->middleware('throttle:otp-verify');

    /*
    | Partner console (Phase 6) — session-cookie + tenant-bound. EnsurePartner
    | resolves the agency from the SESSION only and pushes it into both the
    | Eloquent scope and Postgres RLS. Sign-in / register land in P6-C..E.
    */
    // Partner registration + OTP (public, throttled). Creates a reviewable
    // application; the email-change is bound to the server-side flow_id (P6-D).
    $pa = PartnerAuthController::class;
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

    Route::middleware(['auth:web', EnsurePartner::class])->group(function () {
        $console = PartnerConsoleController::class;
        Route::get('partner/me', [$console, 'me']);
        Route::get('partner/members', [$console, 'members']);

        // Phase 7 — console data surfaces (all tenant-scoped).
        $students = PartnerStudentController::class;
        Route::get('partner/students', [$students, 'index']);
        Route::post('partner/students', [$students, 'store']);

        $apps = PartnerApplicationController::class;
        Route::get('partner/applications', [$apps, 'index']);
        Route::post('partner/applications', [$apps, 'store']);
        Route::get('partner/dashboard/kpis', [$apps, 'kpis']);
        Route::get('partner/dashboard/deadlines', [$apps, 'deadlines']);

        $enq = PartnerEnquiryController::class;
        Route::get('partner/enquiries', [$enq, 'index']);
        Route::post('partner/enquiries', [$enq, 'store']);
        Route::get('partner/enquiries/documents/{doc}/download', [$enq, 'download']);

        $ref = PartnerReferralController::class;
        Route::get('partner/referral-link', [$ref, 'show']);
        Route::post('partner/referral-link/regenerate', [$ref, 'regenerate']);

        $notif = PartnerNotificationController::class;
        Route::get('partner/notifications', [$notif, 'index']);
        Route::post('partner/notifications/read', [$notif, 'read']);

        Route::get('partner/resources', [PartnerResourceController::class, 'index']);

        // Phase 8 — program search + detail over the flat catalogue index
        // (public reference data, but console-only + per-partner rate-limited).
        $programs = PartnerProgramController::class;
        Route::get('partner/programs/search', [$programs, 'search'])->middleware('throttle:program-search');
        Route::get('partner/programs/compare', [$programs, 'compare'])->middleware('throttle:program-search');
        Route::get('partner/programs/{program}', [$programs, 'show'])->whereNumber('program')->middleware('throttle:program-search');

        /*
        | Phase 9D — an application is only processable with the student's
        | paperwork behind it. The agency files on the student's behalf, so the
        | agency must be able to supply those documents; before this only the
        | student could, through their own portal.
        */
        $pdocs = PartnerStudentDocumentController::class;
        Route::get('partner/students/{student}/documents', [$pdocs, 'index'])->whereNumber('student');
        Route::post('partner/students/{student}/documents/{type}', [$pdocs, 'store'])->whereNumber('student');
        Route::delete('partner/students/{student}/documents/{type}', [$pdocs, 'destroy'])->whereNumber('student');
        Route::get('partner/students/{student}/documents/{type}/download', [$pdocs, 'download'])->whereNumber('student');

        // Full view of one application: status history + document readiness.
        Route::get('partner/applications/{application}', [PartnerApplicationController::class, 'show'])
            ->whereNumber('application');

        // Phase 8E — tenant-scoped program shortlist saved to a student.
        $short = PartnerShortlistController::class;
        Route::get('partner/students/{student}/shortlist', [$short, 'index'])->whereNumber('student');
        Route::post('partner/students/{student}/shortlist', [$short, 'store'])->whereNumber('student');
        Route::delete('partner/students/{student}/shortlist/{program}', [$short, 'destroy'])->whereNumber('student')->whereNumber('program');
    });

    // PUBLIC referral resolver for the QR-registration landing (rate-limited).
    Route::get('referral/{slug}', [PublicReferralController::class, 'resolve'])
        ->middleware('throttle:referral-resolve');

    // PUBLIC single-use enquiry-doc stream (opaque token = capability). Throttled.
    Route::get('partner/documents/dl/{token}', [PartnerEnquiryController::class, 'stream'])
        ->middleware('throttle:otp-verify');
});
