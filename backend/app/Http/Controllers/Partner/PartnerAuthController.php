<?php

namespace App\Http\Controllers\Partner;

use App\Enums\ApplicationReviewStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\AccountExistsMail;
use App\Mail\OtpMail;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Mail\ResetMail;
use App\Models\AuthEvent;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\EmailVerificationCode;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\PartnerApplication;
use App\Models\User;
use App\Rules\NotBreachedPassword;
use App\Services\OtpService;
use App\Services\PasswordResetService;
use App\Support\DummyHash;
use App\Support\EmailMask;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 6 — partner (agency) registration + auth. Registration creates a
 * REVIEWABLE application (a pending_verification user + a partner_application),
 * NEVER a live tenant. Enumeration-safe: the response is uniform whether or not
 * the email/agency already exists. OTP/verify/reset reuse the Phase 4 primitives.
 */
class PartnerAuthController extends Controller
{
    public function __construct(private readonly OtpService $otp)
    {
    }

    /**
     * POST /api/partner/register — the 3-step wizard payload (agency + person +
     * password). Creates the application, issues an OTP flow_id, returns it.
     */
    public function register(Request $request): JsonResponse
    {
        if ($blocked = $this->turnstileGate($request)) {
            return $blocked;
        }

        $data = $request->validate([
            'agency' => ['required', 'string', 'max:160'],
            'country' => ['required', 'string', Rule::in(config('partner.countries'))],
            'city' => ['required', 'string', 'max:90'],
            'person' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'dial' => ['required', 'string', 'max:8'],
            'phone' => ['required', 'string', 'max:20', $this->minDigits(6)],
            'password' => ['required', 'string', 'confirmed', Password::min(8), new NotBreachedPassword, 'max:1024'],
            // #paRgAgree attests BOTH the terms AND authority to bind the agency.
            'agree' => ['accepted'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $existing = User::where('email', $email)->first();

        if (! $existing) {
            $user = new User;
            $user->forceFill([
                'name' => $data['person'],
                'email' => $email,
                'phone' => $this->composePhone($data['dial'], $data['phone']),
                'password' => $data['password'],           // 'hashed' cast → argon2id
                'status' => UserStatus::PendingVerification,
            ])->save();

            $application = PartnerApplication::create([
                'agency_name' => $data['agency'], 'country' => $data['country'], 'city' => $data['city'],
                'contact_person' => $data['person'], 'work_email' => $email,
                'phone_cc' => $data['dial'], 'phone_national' => preg_replace('/\D/', '', $data['phone']),
                'user_id' => $user->id,
                'terms_accepted_version' => config('partner.terms_version'),
                'authorised_signatory_attested' => true,   // stored, not merely validated
                'submitted_at' => now(), 'submitted_ip' => $request->ip(),
                'review_status' => ApplicationReviewStatus::Pending,
            ]);

            // Duplicate-agency: a SOFT signal to staff review, never a client error.
            if ($this->looksDuplicate($data['agency'], $data['country'], $application->id)) {
                $application->update(['review_notes' => 'Possible duplicate — same legal name + country already on file.']);
            }

            $issued = $this->otp->issue($email, $user->id, 'partner_register', $request->ip());
            Mail::to($email)->send(new OtpMail($issued['code'], OtpService::TTL_MINUTES));
            AuthEvent::record('partner_registered', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);
            $flow = $issued['record'];
        } else {
            // Enumeration-safe decoy: identical response; the owner gets a notice.
            $issued = $this->otp->issue($email, $existing->id, 'partner_register', $request->ip());
            Mail::to($email)->send(new AccountExistsMail);
            AuthEvent::record('partner_register_existing_email', ['user_id' => $existing->id, 'email' => $email, 'ip' => $request->ip()]);
            $flow = $issued['record'];
        }

        return response()->json([
            'flow_id' => $flow->flow_id,
            'email_masked' => EmailMask::mask($email),
        ], 201)->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/partner/signin — resolve the agency + seat from an ACTIVE
     * membership of an APPROVED agency, and bind them to the session. Wrong
     * password is a generic 401; a correct login against a not-yet-active agency
     * gets the review-gate message (post-auth, so no enumeration leak).
     */
    public function signin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();
        $passwordOk = $user ? Hash::check($data['password'], $user->password) : DummyHash::verifyAbsent($data['password']);

        $reject = fn (string $why) => tap(
            response()->json(['message' => 'Invalid credentials.'], 401)->header('Cache-Control', 'no-store'),
            fn () => AuthEvent::record('partner_signin_'.$why, ['user_id' => $user?->id, 'email' => $email, 'ip' => $request->ip()]),
        );

        if (! $user || ! $passwordOk) {
            $user?->registerFailedLogin();

            return $reject('failed');
        }
        if ($user->isLocked()) {
            return $reject('locked');
        }

        // Resolve an ACTIVE seat whose agency is APPROVED (status gate). Bypass
        // the tenant scope — there is no bound tenant yet at sign-in.
        $membership = PartnerAgencyMember::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('user_id', $user->id)->where('status', MemberStatus::Active->value)
            ->get()
            ->first(fn (PartnerAgencyMember $m) => PartnerAgency::find($m->agency_id)?->status->canOperate());

        if (! $membership) {
            // Correct credentials but no live tenant → review-gate copy.
            AuthEvent::record('partner_signin_not_active', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);

            return response()->json([
                'message' => "This account isn't active yet. Our partner team reviews every application before an account goes live.",
            ], 403)->header('Cache-Control', 'no-store');
        }

        $request->session()->regenerate();
        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->put('active_scope', 'partner');
        $request->session()->put('active_partner_agency_id', $membership->agency_id);
        $request->session()->put('active_seat_role', $membership->seat_role->value);
        $user->markLoginSuccess();
        AuthEvent::record('partner_signin_success', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);

        return response()->json(['ok' => true, 'agency' => ['id' => $membership->agency_id]])->header('Cache-Control', 'no-store');
    }

    /** POST /api/partner/logout — end the partner session. */
    public function logout(Request $request): JsonResponse
    {
        $id = $request->user()?->id;
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        AuthEvent::record('partner_logout', ['user_id' => $id, 'ip' => $request->ip()]);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    /** POST /api/partner/password/forgot — enumeration-safe, always 202. */
    public function forgotRequest(Request $request, PasswordResetService $resets): JsonResponse
    {
        if ($blocked = $this->turnstileGate($request)) {
            return $blocked;
        }

        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        $isPartner = $user && ($user->hasRole(Role::PartnerOwner) || $user->hasRole(Role::PartnerCounsellor)
            || PartnerApplication::where('user_id', $user->id)->exists());

        if ($user && $isPartner && $user->status !== \App\Enums\UserStatus::Suspended) {
            $issued = $resets->request($user, $request->ip());
            $url = rtrim((string) config('app.url'), '/').'/vfi-partner-reset.html?token='.$issued['token'];
            Mail::to($email)->send(new ResetMail($url, PasswordResetService::TTL_MINUTES));
            AuthEvent::record('partner_reset_requested', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);
        } else {
            hash('sha256', random_bytes(32));   // comparable work on the empty branch
            AuthEvent::record('partner_reset_no_account', ['email' => $email, 'ip' => $request->ip()]);
        }

        return response()->json(['message' => 'If an account exists for that address, a reset link is on its way.'], 202)
            ->header('Cache-Control', 'no-store');
    }

    /** POST /api/partner/password/reset/submit — consume; revokes ALL sessions. */
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

    /** POST /api/partner/email/verify — {flow_id, code}. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['flow_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:6']]);
        $result = $this->otp->verify($data['flow_id'], $data['code']);
        $rec = $result['record'];

        AuthEvent::record('partner_otp_'.$result['status'], ['user_id' => $rec?->user_id, 'email' => $rec?->email, 'ip' => $request->ip()]);

        if ($result['status'] === 'ok') {
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

    /** POST /api/partner/email/code — {flow_id} resend (rotate the code). */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['flow_id' => ['required', 'uuid']]);
        $rec = EmailVerificationCode::where('flow_id', $data['flow_id'])->where('purpose', 'partner_register')->first();
        if (! $rec) {
            return response()->json(['ok' => false, 'message' => 'This verification session is no longer valid.'], 422)->header('Cache-Control', 'no-store');
        }

        try {
            $issued = $this->otp->rotate($rec, $request->ip());
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => 'Please wait a few seconds before requesting another code.'], 429)->header('Cache-Control', 'no-store');
        }

        Mail::to($rec->email)->send(new OtpMail($issued['code'], OtpService::TTL_MINUTES));

        return response()->json(['ok' => true, 'email_masked' => EmailMask::mask($rec->email)])->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/partner/email/change — {flow_id, email}. Hardened: the change
     * requires possession of the server-side flow_id (NOT a URL ?email=), so an
     * attacker who merely knows the applicant's address cannot redirect the code.
     * It restarts the flow (invalidates prior codes) and is rate-limited to
     * `partner.max_email_changes` per registration (docs §4).
     */
    public function emailChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flow_id' => ['required', 'uuid'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $rec = EmailVerificationCode::where('flow_id', $data['flow_id'])->where('purpose', 'partner_register')->first();
        $application = $rec ? PartnerApplication::where('user_id', $rec->user_id)->first() : null;
        if (! $rec || ! $application) {
            return response()->json(['ok' => false, 'message' => 'This verification session is no longer valid.'], 422)->header('Cache-Control', 'no-store');
        }

        if ($application->email_change_count >= (int) config('partner.max_email_changes', 2)) {
            return response()->json(['ok' => false, 'message' => 'You have changed the address too many times. Please register again.'], 429)->header('Cache-Control', 'no-store');
        }

        $newEmail = mb_strtolower(trim($data['email']));
        $application->increment('email_change_count');

        // Enumeration-safe + anti-hijack: if the new address already belongs to a
        // DIFFERENT account, do not re-point the code to it — notify that address
        // instead, with the same success-shaped response.
        $clash = User::where('email', $newEmail)->where('id', '!=', $rec->user_id)->exists();
        if ($newEmail === $rec->email || $clash) {
            if ($clash) {
                Mail::to($newEmail)->send(new AccountExistsMail);
            }

            return response()->json(['ok' => true, 'email_masked' => EmailMask::mask($newEmail)])->header('Cache-Control', 'no-store');
        }

        // Move the pending registration to the new address and restart the flow.
        User::whereKey($rec->user_id)->update(['email' => $newEmail]);
        $application->update(['work_email' => $newEmail]);
        $issued = $this->otp->reissue($rec, $newEmail, $request->ip());
        Mail::to($newEmail)->send(new OtpMail($issued['code'], OtpService::TTL_MINUTES));
        AuthEvent::record('partner_email_changed', ['user_id' => $rec->user_id, 'email' => $newEmail, 'ip' => $request->ip()]);

        return response()->json(['ok' => true, 'email_masked' => EmailMask::mask($newEmail)])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/verify/context?flow_id= — masked email for display (no PII in URL). */
    public function verifyContext(Request $request): JsonResponse
    {
        $rec = EmailVerificationCode::where('flow_id', (string) $request->query('flow_id'))
            ->where('purpose', 'partner_register')->first();
        if (! $rec) {
            return response()->json(['message' => 'Not found.'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json(['email_masked' => EmailMask::mask($rec->email)])->header('Cache-Control', 'no-store');
    }

    // ---- helpers ----

    private function looksDuplicate(string $agency, string $country, int $exceptAppId): bool
    {
        $inAgencies = PartnerAgency::where('legal_name', $agency)->where('country', $country)->exists();
        $inApps = PartnerApplication::where('id', '!=', $exceptAppId)
            ->where('agency_name', $agency)->where('country', $country)
            ->where('review_status', ApplicationReviewStatus::Pending->value)->exists();

        return $inAgencies || $inApps;
    }

    private function turnstileGate(Request $request): ?JsonResponse
    {
        if (Turnstile::passes($request)) {
            return null;
        }

        return response()->json(['message' => 'Verification failed. Please try again.'], 422)
            ->header('Cache-Control', 'no-store');
    }

    private function composePhone(string $cc, string $national): string
    {
        return '+'.preg_replace('/\D/', '', $cc).preg_replace('/\D/', '', $national);
    }

    private function minDigits(int $n): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail) use ($n) {
            if (strlen(preg_replace('/\D/', '', (string) $value)) < $n) {
                $fail('Enter a valid phone number.');
            }
        };
    }
}
