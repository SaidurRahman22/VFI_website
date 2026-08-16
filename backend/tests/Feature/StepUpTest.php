<?php

namespace Tests\Feature;

use App\Models\AuthEvent;
use App\Models\User;
use App\Services\TotpService;
use App\Support\StepUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Phase 9A — step-up re-auth guarding the privileged writes. An open admin
 * session must not be enough to change who holds which role.
 */
class StepUpTest extends TestCase
{
    use RefreshDatabase;

    private function enrolled(): array
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = User::factory()->create(['mfa_secret' => $secret, 'mfa_enrolled_at' => now()]);

        return [$user->fresh(), $secret];
    }

    /** The code the user's authenticator would be showing right now. */
    private function currentCode(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    public function test_a_user_without_2fa_cannot_step_up(): void
    {
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        StepUp::assert($user, '123456', 'role_grant');
    }

    public function test_a_malformed_code_is_refused(): void
    {
        [$user] = $this->enrolled();

        $this->expectException(\RuntimeException::class);
        StepUp::assert($user, '12', 'role_grant');
    }

    public function test_a_wrong_code_is_refused_and_logged(): void
    {
        [$user] = $this->enrolled();

        try {
            StepUp::assert($user, '000000', 'role_grant');
            $this->fail('a wrong code must be refused');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, AuthEvent::where('event', 'stepup_failed')->where('user_id', $user->id)->count());
    }

    public function test_a_valid_code_passes_once_then_cannot_be_replayed(): void
    {
        [$user, $secret] = $this->enrolled();
        $code = $this->currentCode($secret);

        StepUp::assert($user, $code, 'role_grant');
        $this->assertSame(1, AuthEvent::where('event', 'stepup_ok')->where('user_id', $user->id)->count());
        $this->assertNotNull($user->fresh()->mfa_last_used_slice);

        // the same six digits must not work a second time
        $this->expectException(\RuntimeException::class);
        StepUp::assert($user->fresh(), $code, 'role_grant');
    }
}
