<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\AuthEvent;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 4B — email OTP state machine (docs §2, Security §OTP). Owns issue /
 * rotate / verify; the controller owns HTTP + email delivery. Guarantees:
 *   - code is CSPRNG (random_int), stored ONLY as an argon2id hash;
 *   - 10-minute TTL; single-use (consumed_at); max 5 attempts then destroyed;
 *   - a new issue supersedes any prior live code for the same email+purpose;
 *   - resend rotates the code on the SAME flow_id (URL stays valid) and the old
 *     code stops working — with a 30s minimum interval.
 */
class OtpService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_MIN_INTERVAL = 30;   // seconds

    /**
     * Issue a fresh OTP for an email+purpose, superseding any prior live code.
     *
     * @return array{record: EmailVerificationCode, code: string}
     */
    public function issue(string $email, ?int $userId, string $purpose, ?string $ip): array
    {
        $email = mb_strtolower(trim($email));

        // Supersede any still-live code for this address+purpose.
        EmailVerificationCode::query()
            ->where('email', $email)->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Date::now()]);

        $code = $this->newCode();
        $record = EmailVerificationCode::create([
            'flow_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'attempts_used' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => Date::now()->addMinutes(self::TTL_MINUTES),
            'last_sent_at' => Date::now(),
            'request_ip' => $ip,
        ]);

        return ['record' => $record, 'code' => $code];
    }

    /**
     * Resend: rotate the code on an existing flow (invalidates the previous one)
     * and reset the attempt counter + TTL. Throws 'too_soon' inside the 30s window.
     *
     * @return array{record: EmailVerificationCode, code: string}
     */
    public function rotate(EmailVerificationCode $record, ?string $ip): array
    {
        if ($record->last_sent_at && $record->last_sent_at->diffInSeconds(Date::now()) < self::RESEND_MIN_INTERVAL) {
            throw new \RuntimeException('too_soon');
        }

        return $this->reissue($record, null, $ip);
    }

    /**
     * Regenerate the code on an existing flow (optionally re-pointing it to a new
     * email). Resets attempts + TTL and invalidates the prior code. No cooldown —
     * used by rotate() (which adds the 30s check) and by the email-change flow.
     *
     * @return array{record: EmailVerificationCode, code: string}
     */
    public function reissue(EmailVerificationCode $record, ?string $newEmail, ?string $ip): array
    {
        $code = $this->newCode();
        $attrs = [
            'code_hash' => Hash::make($code),
            'attempts_used' => 0,
            'consumed_at' => null,
            'expires_at' => Date::now()->addMinutes(self::TTL_MINUTES),
            'last_sent_at' => Date::now(),
            'request_ip' => $ip,
        ];
        if ($newEmail !== null) {
            $attrs['email'] = mb_strtolower(trim($newEmail));
        }
        $record->forceFill($attrs)->save();

        return ['record' => $record, 'code' => $code];
    }

    /**
     * Verify a submitted code against a flow. On success consumes the code and
     * promotes the user to a verified/active account.
     *
     * @return array{status: string, record: ?EmailVerificationCode}
     *                                                               status ∈ ok | invalid | expired | locked | not_found
     */
    public function verify(string $flowId, string $code): array
    {
        $record = EmailVerificationCode::where('flow_id', $flowId)->first();

        if (! $record || $record->isConsumed()) {
            return ['status' => 'not_found', 'record' => $record];
        }
        if ($record->isExpired()) {
            return ['status' => 'expired', 'record' => $record];
        }
        if ($record->attemptsExhausted()) {
            $record->markConsumed();   // destroy an exhausted code

            return ['status' => 'locked', 'record' => $record];
        }

        // ------------------------------------------------------------------
        // DEMO BYPASS — accepts one fixed code so the product can be walked
        // through before a mail sender exists (OTP mail currently goes to the
        // log, so no real code ever reaches an inbox).
        //
        // OFF unless AUTH_DEMO_OTP is explicitly set, and it is NOT a shortcut
        // for anything else: the flow, expiry, attempt caps and single-use
        // consumption all still apply. Every use is recorded as its own auth
        // event so the window it was open for is auditable after the fact.
        //
        // MUST be cleared before real users are onboarded — see
        // Developer_requier.md, Priority 1.
        // ------------------------------------------------------------------
        $demo = (string) config('auth.demo_otp', '');
        if ($demo !== '' && hash_equals($demo, $code)) {
            AuthEvent::record('otp_demo_bypass', [
                'user_id' => $record->user_id,
                'email' => $record->email ?? null,
                'ip' => request()?->ip(),
                'context' => ['purpose' => $record->purpose, 'flow_id' => $flowId],
            ]);
            Log::warning('OTP demo bypass used — AUTH_DEMO_OTP is set. Clear it before onboarding real users.', [
                'purpose' => $record->purpose,
            ]);
        } elseif (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts_used');
            if ($record->fresh()->attemptsExhausted()) {
                $record->markConsumed();

                return ['status' => 'locked', 'record' => $record];
            }

            return ['status' => 'invalid', 'record' => $record];
        }

        $record->markConsumed();

        // Promote the account: signup/partner OTP verified → active + email_verified.
        if ($record->user_id && in_array($record->purpose, ['signup_student', 'partner_register'], true)) {
            $user = User::find($record->user_id);
            if ($user && $user->email_verified_at === null) {
                $user->forceFill([
                    'email_verified_at' => Date::now(),
                    'status' => UserStatus::Active,
                ])->save();
            }
        }

        return ['status' => 'ok', 'record' => $record];
    }

    /** Cryptographically-random zero-padded 6-digit code. */
    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
