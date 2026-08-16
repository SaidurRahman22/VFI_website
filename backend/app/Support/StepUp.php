<?php

namespace App\Support;

use App\Models\AuthEvent;
use App\Models\User;
use App\Services\TotpService;
use RuntimeException;

/**
 * Phase 9A — step-up re-authentication for the privileged writes.
 *
 * An open admin session is not enough to change who holds which role: sessions
 * get left open on unlocked laptops, and role changes are the one action that
 * can hand somebody the whole system. The actor must re-prove possession of
 * their authenticator at the moment of the write.
 *
 * Replay is blocked the same way the login flow does it — a TOTP code is
 * accepted once, and the time slice it belonged to is remembered on the user, so
 * the same six digits cannot be submitted twice within their window.
 *
 * Both the success and the failure are written to `auth_events`, because a burst
 * of failed step-ups is a signal worth alerting on.
 */
class StepUp
{
    public static function assert(User $actor, ?string $code, string $action): void
    {
        if (! $actor->hasMfa()) {
            throw new RuntimeException('Enrol two-factor authentication before performing this action.');
        }

        $code = preg_replace('/\D/', '', (string) $code);
        if (strlen($code) !== 6) {
            throw new RuntimeException('Enter the 6-digit code from your authenticator app.');
        }

        $slice = app(TotpService::class)->verify($actor->mfa_secret, $code, $actor->mfa_last_used_slice);

        if ($slice === false) {
            AuthEvent::record('stepup_failed', [
                'user_id' => $actor->id, 'email' => $actor->email, 'ip' => request()?->ip(), 'context' => ['action' => $action],
            ]);
            throw new RuntimeException('That code is not valid. Check your authenticator and try again.');
        }

        // burn the slice so the same code cannot be replayed
        $actor->forceFill(['mfa_last_used_slice' => $slice])->save();

        AuthEvent::record('stepup_ok', [
            'user_id' => $actor->id, 'email' => $actor->email, 'ip' => request()?->ip(), 'context' => ['action' => $action],
        ]);
    }
}
