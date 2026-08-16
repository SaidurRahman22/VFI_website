<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\AccountExistsMail;
use App\Mail\OtpMail;
use App\Mail\ResetMail;
use App\Models\AuthEvent;
use App\Models\EmailVerificationCode;
use App\Models\Student\Student;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Models\UserRole;
use App\Rules\NotBreachedPassword;
use App\Services\OtpService;
use App\Services\PasswordResetService;
use App\Services\ReferralAttribution;
use App\Support\DummyHash;
use App\Support\EmailMask;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 4B — student registration + email OTP (docs §1–2). The API surface is
 * deliberately identical for existing vs new emails (enumeration safety), and
 * the email never rides in a URL: register returns an opaque `flow_id`.
 */
class StudentAuthController extends Controller
{
    /** Version of the terms recorded against each acceptance. */
    private const TERMS_VERSION = '2026-08';

    public function __construct(
        private readonly OtpService $otp,
        private readonly ReferralAttribution $attribution,
    ) {}

    /**
     * POST /api/register — create a pending student, record consent, start an
     * OTP flow. Response is uniform whether or not the email already exists.
     */
    public function register(Request $request): JsonResponse
    {
        if ($blocked = $this->turnstileGate($request)) {
            return $blocked;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // Advisory strength meter never gates server-side; length + breach-list
            // only. No composition rules.
            'password' => ['required', 'string', Password::min(8), new NotBreachedPassword, 'max:1024'],
            'cc' => ['required', 'string', 'max:8'],
            'phone' => ['required', 'string', 'max:20'],
            'agree' => ['accepted'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $phone = $this->composePhone($data['cc'], $data['phone']);
        $existing = User::where('email', $email)->first();
        // A QR ref (optional) attributes a signup to an agency — only ever counted
        // after email verification (docs §6). Invalid/revoked slugs are ignored.
        $link = $this->attribution->resolveLink($request->input('ref'));

        if (! $existing) {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $phone,
                'password' => $data['password'],       // 'hashed' cast → argon2id
                'status' => UserStatus::PendingVerification,
            ])->save();

            UserRole::create([
                'user_id' => $user->id, 'role' => Role::Student,
                'agency_id' => null, 'granted_at' => now(),
            ]);
            TermsAcceptance::create([
                'user_id' => $user->id, 'document' => 'terms', 'version' => self::TERMS_VERSION,
                'accepted_at' => now(), 'ip' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            $this->attribution->capture($link, Student::resolveFor($user));

            $issued = $this->otp->issue($email, $user->id, 'signup_student', $request->ip());
            Mail::to($email)->send(new OtpMail($issued['code'], OtpService::TTL_MINUTES));
            AuthEvent::record('student_registered', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);
            $flow = $issued['record'];
        } elseif ($existing->status === UserStatus::PendingVerification) {
            // Same person, not yet verified → resume: re-issue the signup code.
            $this->attribution->capture($link, Student::resolveFor($existing));
            $issued = $this->otp->issue($email, $existing->id, 'signup_student', $request->ip());
            Mail::to($email)->send(new OtpMail($issued['code'], OtpService::TTL_MINUTES));
            $flow = $issued['record'];
        } elseif ($link && ($student = Student::resolveFor($existing))->agency_id === null
            && $existing->status !== UserStatus::Suspended) {
            // QR-only claim of an UNOWNED self-signup: send a real code so they
            // re-prove email control, then attribution + ownership on verify.
            $this->attribution->capture($link, $student);
            $issued = $this->otp->issue($email, $existing->id, 'signup_student', $request->ip());
            Mail::to($email)->send(new OtpMail($issued['code'], OtpService::TTL_MINUTES));
            AuthEvent::record('student_qr_claim_started', ['user_id' => $existing->id, 'email' => $email, 'ip' => $request->ip()]);
            $flow = $issued['record'];
        } else {
            // Already a real account (owned or no valid ref) → decoy: identical
            // response, but the owner gets a "you already have an account" note.
            $issued = $this->otp->issue($email, $existing->id, 'signup_student', $request->ip());
            Mail::to($email)->send(new AccountExistsMail);
            AuthEvent::record('register_existing_email', ['user_id' => $existing->id, 'email' => $email, 'ip' => $request->ip()]);
            $flow = $issued['record'];
        }

        return response()->json([
            'flow_id' => $flow->flow_id,
            'email_masked' => EmailMask::mask($email),
        ], 201)->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/login — student sign-in. One generic failure for every cause
     * (wrong password / unknown account / locked / wrong scope / suspended), with
     * a constant-time password check. An unverified student is allowed a session
     * but is flagged must_verify (uploads/submissions gated in Phase 5).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        $passwordOk = $user
            ? Hash::check($data['password'], $user->password)
            : DummyHash::verifyAbsent($data['password']);

        // Every rejection path returns the SAME response (no enumeration, no
        // "your account is locked" oracle).
        $reject = fn (string $why) => tap(
            response()->json(['message' => 'Invalid credentials.'], 401)->header('Cache-Control', 'no-store'),
            fn () => AuthEvent::record('student_login_'.$why, ['user_id' => $user?->id, 'email' => $email, 'ip' => $request->ip()]),
        );

        if (! $user || ! $passwordOk) {
            $user?->registerFailedLogin();

            return $reject('failed');
        }
        if ($user->isLocked()) {
            return $reject('locked');
        }
        if (! $user->hasRole(Role::Student)) {
            return $reject('wrong_scope');   // valid non-student creds must not be revealed here
        }
        if (! $user->status->canStudentSignIn()) {
            return $reject('suspended');
        }

        // Establish the student session (new id after auth). `remember` extends lifetime.
        $request->session()->regenerate();
        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->put('active_scope', 'student');
        $user->markLoginSuccess();
        AuthEvent::record('student_login_success', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);

        return response()->json([
            'user' => $this->publicUser($user),
            'must_verify' => $user->email_verified_at === null,
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/student/me — the signed-in student (student scope only). */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->publicUser($user),
            'must_verify' => $user->email_verified_at === null,
        ])->header('Cache-Control', 'no-store');
    }

    /** POST /api/student/logout — end the student session. */
    public function logout(Request $request): JsonResponse
    {
        $id = $request->user()?->id;
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        AuthEvent::record('student_logout', ['user_id' => $id, 'ip' => $request->ip()]);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    /** POST /api/verify — check {flow_id, code}. Returns {ok:bool}. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flow_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:6'],
        ]);

        $result = $this->otp->verify($data['flow_id'], $data['code']);
        $rec = $result['record'];

        AuthEvent::record('otp_'.$result['status'], [
            'user_id' => $rec?->user_id, 'email' => $rec?->email, 'ip' => $request->ip(),
        ]);

        if ($result['status'] === 'ok') {
            // Email verified → a pending QR referral now COUNTS (docs §6).
            if ($rec?->user_id && ($user = User::find($rec->user_id))) {
                $this->attribution->convertForUser($user);
            }

            return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
        }

        $message = match ($result['status']) {
            'expired' => 'This code has expired. Request a new one.',
            'locked' => 'Too many attempts. Request a new code.',
            'not_found' => 'This verification session is no longer valid.',
            default => 'That code is not correct.',
        };

        return response()->json(['ok' => false, 'message' => $message])->header('Cache-Control', 'no-store');
    }

    /** POST /api/verify/resend — rotate the code on an existing flow. */
    public function resend(Request $request): JsonResponse
    {
        if ($blocked = $this->turnstileGate($request)) {
            return $blocked;
        }

        $data = $request->validate(['flow_id' => ['required', 'uuid']]);
        $rec = EmailVerificationCode::where('flow_id', $data['flow_id'])->first();

        if (! $rec) {
            return response()->json(['ok' => false, 'message' => 'This verification session is no longer valid. Please register again.'], 422)
                ->header('Cache-Control', 'no-store');
        }

        try {
            $issued = $this->otp->rotate($rec, $request->ip());
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => 'Please wait a few seconds before requesting another code.'], 429)
                ->header('Cache-Control', 'no-store');
        }

        Mail::to($rec->email)->send(new OtpMail($issued['code'], OtpService::TTL_MINUTES));

        return response()->json(['ok' => true, 'email_masked' => EmailMask::mask($rec->email)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/password/reset — request (and resend). Fire-and-forget and
     * enumeration-safe: ALWAYS 202 with the same body whether or not the address
     * is on file. A real student gets a link; anyone else gets silence.
     */
    public function forgotRequest(Request $request, PasswordResetService $resets): JsonResponse
    {
        if ($blocked = $this->turnstileGate($request)) {
            return $blocked;
        }

        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        if ($user && $user->hasRole(Role::Student) && $user->status->canStudentSignIn()) {
            $issued = $resets->request($user, $request->ip());
            $url = rtrim((string) config('app.url'), '/').'/student-reset.html?token='.$issued['token'];
            Mail::to($email)->send(new ResetMail($url, PasswordResetService::TTL_MINUTES));
            AuthEvent::record('password_reset_requested', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);
        } else {
            // Comparable work on the empty branch (blunts timing enumeration).
            hash('sha256', random_bytes(32));
            AuthEvent::record('password_reset_no_account', ['email' => $email, 'ip' => $request->ip()]);
        }

        return response()->json(
            ['message' => 'If an account exists for that address, a reset link is on its way.'], 202
        )->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/password/reset/submit — consume {token, password}. Enforces the
     * password policy server-side; a successful reset revokes every session.
     */
    public function resetSubmit(Request $request, PasswordResetService $resets): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), new NotBreachedPassword, 'max:1024'],
        ]);

        $result = $resets->consume($data['token'], $data['password'], $request->ip());

        if ($result['status'] === 'ok') {
            return response()->json(['ok' => true, 'message' => 'Your password has been reset. Please sign in.'])
                ->header('Cache-Control', 'no-store');
        }

        $message = $result['status'] === 'expired'
            ? 'This reset link has expired. Request a new one.'
            : 'This reset link is invalid or has already been used.';

        return response()->json(['ok' => false, 'message' => $message], 422)->header('Cache-Control', 'no-store');
    }

    /** GET /api/verify/context?flow_id= — masked email for display (no PII in URL). */
    public function verifyContext(Request $request): JsonResponse
    {
        $rec = EmailVerificationCode::where('flow_id', (string) $request->query('flow_id'))->first();
        if (! $rec) {
            return response()->json(['message' => 'Not found.'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'email_masked' => EmailMask::mask($rec->email),
            'purpose' => $rec->purpose,
        ])->header('Cache-Control', 'no-store');
    }

    /** Bot gate for unauthenticated writes — a 422 response, or null to proceed. */
    private function turnstileGate(Request $request): ?JsonResponse
    {
        if (Turnstile::passes($request)) {
            return null;
        }

        return response()->json(['message' => 'Verification failed. Please try again.'], 422)
            ->header('Cache-Control', 'no-store');
    }

    /** Safe public projection of a student user (never the password/mfa fields). */
    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified' => $user->email_verified_at !== null,
        ];
    }

    /** Compose a display/storage phone from country code + national number. */
    private function composePhone(string $cc, string $national): string
    {
        $cc = '+'.preg_replace('/\D/', '', $cc);
        $national = preg_replace('/\D/', '', $national);

        return $cc.$national;
    }
}
