<?php

namespace App\Services;

use App\Models\AuthEvent;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 4D — password-reset token lifecycle (docs §3, Security §Reset token).
 * The raw 32-byte CSPRNG token exists only in the emailed link; at rest we keep
 * the sha256 hash and look up by it. Guarantees: single-use, supersede-on-new,
 * 45-min TTL, and — critically — a successful reset REVOKES ALL of the user's
 * sessions and invalidates every other outstanding token for that user.
 */
class PasswordResetService
{
    public const TTL_MINUTES = 45;

    /**
     * Mint a token for a user, superseding any prior live token.
     *
     * @return array{token: string, record: PasswordResetToken}
     */
    public function request(User $user, ?string $ip): array
    {
        PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')->whereNull('invalidated_by')
            ->update(['invalidated_by' => 'superseded']);

        $raw = bin2hex(random_bytes(32));    // 256-bit, brute-force infeasible
        $record = PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $raw),
            'requested_for_email' => $user->email,
            'expires_at' => Date::now()->addMinutes(self::TTL_MINUTES),
            'requested_ip' => $ip,
        ]);

        return ['token' => $raw, 'record' => $record];
    }

    /**
     * Consume a raw token + set the new password. On success revokes all
     * sessions and invalidates the user's other tokens.
     *
     * @return array{status: string, user?: User} status ∈ ok | invalid | expired
     */
    public function consume(string $rawToken, string $newPassword, ?string $ip): array
    {
        $record = PasswordResetToken::where('token_hash', hash('sha256', $rawToken))->first();

        if (! $record || $record->consumed_at !== null || $record->invalidated_by !== null) {
            return ['status' => 'invalid'];
        }
        if ($record->isExpired()) {
            return ['status' => 'expired'];
        }

        $user = $record->user;
        if (! $user) {
            return ['status' => 'invalid'];
        }

        $user->forceFill(['password' => $newPassword])->save();   // 'hashed' cast → argon2id
        $record->markConsumed($ip);

        // Kill every other outstanding token for this user.
        PasswordResetToken::query()
            ->where('user_id', $user->id)->where('id', '!=', $record->id)
            ->whereNull('consumed_at')->whereNull('invalidated_by')
            ->update(['invalidated_by' => 'superseded']);

        $this->revokeAllSessions($user);
        AuthEvent::record('password_reset', ['user_id' => $user->id, 'email' => $user->email, 'ip' => $ip]);

        return ['status' => 'ok', 'user' => $user];
    }

    /** Log the user out everywhere: drop DB sessions + rotate the remember token. */
    private function revokeAllSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
