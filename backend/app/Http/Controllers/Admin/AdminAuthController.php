<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Admin sign-in (docs §3, §7, §8). Two steps, both fail-closed:
 *   1. POST /api/admin/login          {email,password}  → password + status + lockout
 *   2. POST /api/admin/login/totp     {code}            → TOTP, then session established
 * plus first-time enrolment:
 *      POST /api/admin/mfa/enroll                        → secret + QR (pending session)
 *      POST /api/admin/mfa/confirm    {code}             → save secret + establish session
 *
 * The user is NOT authenticated until TOTP passes — until then only a
 * server-side "pending" marker exists in the session, so no admin route opens.
 */
class AdminAuthController extends Controller
{
    // A fixed argon2id hash of a random string. We verify against it for unknown
    // accounts so "no such user" and "wrong password" take the same time
    // (enumeration safety, docs §8.2).
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$Y1ZtZUJ2c3hZbUZ4Y0hORQ$0m6l3Yy0oXwqk0m2Qd8m2y6Yk3q3W1Rj0m0mQ4d8m2y';

    public function __construct(private readonly TotpService $totp)
    {
    }

    /** Step 1 — password. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        // Constant-time: always run a hash check, real or dummy.
        $passwordOk = $user
            ? Hash::check($data['password'], $user->password)
            : Hash::check($data['password'], self::DUMMY_HASH) && false;

        if (! $user || ! $passwordOk) {
            if ($user) {
                $user->registerFailedLogin();
            }
            AuthEvent::record('login_failed', [
                'user_id' => $user?->id,
                'email' => $email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->isLocked()) {
            AuthEvent::record('login_locked', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Account temporarily locked. Try again later.'], 423);
        }

        if ($user->status !== UserStatus::Active) {
            return response()->json(['message' => 'Account is not active.'], 403);
        }

        if (! $user->usesAdminPanel()) {
            // Valid non-admin credentials must not reveal they're valid here.
            AuthEvent::record('login_wrong_scope', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // Dev convenience toggle: when TOTP is not required (local only), a
        // correct password establishes the session immediately.
        if (! (bool) config('auth.admin_require_totp', true)) {
            return $this->establishSession($request, $user);
        }

        // Password OK → set a PENDING marker only. Not authenticated yet.
        $request->session()->put('admin_pending_user_id', $user->id);
        $request->session()->put('admin_pending_at', now()->timestamp);

        AuthEvent::record('login_password_ok', ['user_id' => $user->id, 'email' => $email, 'ip' => $request->ip()]);

        return response()->json([
            'step' => $user->hasMfa() ? 'totp' : 'enroll',
        ]);
    }

    /** Step 2 (already-enrolled) — verify TOTP, then establish the session. */
    public function totp(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return response()->json(['message' => 'No sign-in in progress.'], 401);
        }
        if (! $user->hasMfa()) {
            return response()->json(['message' => 'TOTP not enrolled.'], 409);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $slice = $this->totp->verify($user->mfa_secret, $data['code'], $user->mfa_last_used_slice);
        if ($slice === false) {
            AuthEvent::record('totp_failed', ['user_id' => $user->id, 'email' => $user->email, 'ip' => $request->ip()]);
            $user->registerFailedLogin();

            return response()->json(['message' => 'Invalid or reused code.'], 401);
        }

        $user->forceFill(['mfa_last_used_slice' => $slice])->save();  // block replay

        return $this->establishSession($request, $user);
    }

    /** First-time enrolment: issue a secret + QR (requires the pending session). */
    public function enroll(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return response()->json(['message' => 'No sign-in in progress.'], 401);
        }
        if ($user->hasMfa()) {
            return response()->json(['message' => 'Already enrolled.'], 409);
        }

        $secret = $this->totp->generateSecret();
        $request->session()->put('admin_pending_mfa_secret', $secret);  // not persisted until confirmed

        $uri = $this->totp->otpauthUri($user->email, $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => $this->totp->qrSvg($uri),
        ]);
    }

    /** Confirm enrolment with a code, persist the secret, establish the session. */
    public function confirmEnroll(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);
        $secret = $request->session()->get('admin_pending_mfa_secret');
        if (! $user || ! $secret) {
            return response()->json(['message' => 'No enrolment in progress.'], 401);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $slice = $this->totp->verify($secret, $data['code']);
        if ($slice === false) {
            return response()->json(['message' => 'Code did not match. Re-scan and try again.'], 422);
        }

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enrolled_at' => now(),
            'mfa_last_used_slice' => $slice,
        ])->save();
        $request->session()->forget('admin_pending_mfa_secret');

        AuthEvent::record('totp_enrolled', ['user_id' => $user->id, 'email' => $user->email, 'ip' => $request->ip()]);

        return $this->establishSession($request, $user);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->activeRoles()->map(fn ($r) => $r->value)->values(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $id = $request->user()?->id;
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        AuthEvent::record('logout', ['user_id' => $id, 'ip' => $request->ip()]);

        return response()->json(['ok' => true]);
    }

    // ---- helpers ----

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('admin_pending_user_id');
        $at = $request->session()->get('admin_pending_at');
        // pending window: 5 minutes to complete the second factor
        if (! $id || ! $at || (now()->timestamp - $at) > 300) {
            return null;
        }

        return User::find($id);
    }

    private function establishSession(Request $request, User $user): JsonResponse
    {
        $request->session()->regenerate();               // new id after privilege change
        Auth::guard('web')->login($user);
        $request->session()->put('active_scope', 'admin');
        $request->session()->forget(['admin_pending_user_id', 'admin_pending_at']);

        $user->markLoginSuccess();
        AuthEvent::record('login_success', ['user_id' => $user->id, 'email' => $user->email, 'ip' => $request->ip()]);

        return response()->json(['step' => 'done', 'user' => $this->me($request)->getData(true)]);
    }
}
