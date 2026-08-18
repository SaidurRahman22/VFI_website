<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Mail\OtpMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerOtpTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'agency' => 'Acme', 'country' => 'Bangladesh', 'city' => 'Dhaka',
            'person' => 'Jane', 'email' => 'jane@acme.test', 'dial' => '+880', 'phone' => '1712345678',
            'password' => 'a-strong-partner-pass', 'password_confirmation' => 'a-strong-partner-pass', 'agree' => true,
        ], $over);
    }

    /** Register and return [flow_id, plaintext code]. */
    private function register(array $over = []): array
    {
        $flow = $this->postJson('/api/partner/register', $this->payload($over))->json('flow_id');
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return [$flow, $code];
    }

    public function test_verify_rejects_wrong_code_then_activates(): void
    {
        Mail::fake();
        [$flow, $code] = $this->register();

        $this->postJson('/api/partner/email/verify', ['flow_id' => $flow, 'code' => '000000'])
            ->assertStatus(200)->assertJsonPath('ok', false);

        $this->postJson('/api/partner/email/verify', ['flow_id' => $flow, 'code' => $code])
            ->assertStatus(200)->assertJsonPath('ok', true);

        $user = User::where('email', 'jane@acme.test')->firstOrFail();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_verify_context_returns_masked_email_only(): void
    {
        Mail::fake();
        [$flow] = $this->register();
        $this->getJson('/api/partner/verify/context?flow_id='.$flow)
            ->assertStatus(200)->assertJsonMissingPath('email');
    }

    public function test_email_change_requires_the_flow_id_not_the_address(): void
    {
        Mail::fake();
        [$flow] = $this->register(['email' => 'victim@acme.test']);

        // An attacker who knows the applicant's email but NOT the flow_id cannot
        // redirect the code — a bogus/absent flow_id is refused.
        $this->postJson('/api/partner/email/change', ['flow_id' => '11111111-1111-1111-1111-111111111111', 'email' => 'attacker@evil.test'])
            ->assertStatus(422)->assertJsonPath('ok', false);

        // the pending user's email is untouched
        $this->assertTrue(User::where('email', 'victim@acme.test')->exists());
        $this->assertFalse(User::where('email', 'attacker@evil.test')->exists());
    }

    public function test_email_change_with_flow_id_restarts_the_flow(): void
    {
        Mail::fake();
        [$flow, $oldCode] = $this->register(['email' => 'old@acme.test']);

        $this->postJson('/api/partner/email/change', ['flow_id' => $flow, 'email' => 'new@acme.test'])
            ->assertStatus(200)->assertJsonPath('ok', true);

        // pending registration moved to the new address; prior code invalidated
        $this->assertTrue(User::where('email', 'new@acme.test')->exists());
        $this->assertFalse(User::where('email', 'old@acme.test')->exists());
        $this->assertSame('new@acme.test', EmailVerificationCode::where('flow_id', $flow)->value('email'));
        $this->postJson('/api/partner/email/verify', ['flow_id' => $flow, 'code' => $oldCode])
            ->assertJsonPath('ok', false);   // old code no longer works

        // the NEW code (sent to the new address) verifies
        $newCode = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$newCode) {
            $newCode = $m->code;   // last captured = the reissued one

            return true;
        });
        $this->postJson('/api/partner/email/verify', ['flow_id' => $flow, 'code' => $newCode])->assertJsonPath('ok', true);
    }

    public function test_email_change_is_rate_limited(): void
    {
        Mail::fake();
        [$flow] = $this->register(['email' => 'a@acme.test']);

        $this->postJson('/api/partner/email/change', ['flow_id' => $flow, 'email' => 'b@acme.test'])->assertStatus(200);
        $this->postJson('/api/partner/email/change', ['flow_id' => $flow, 'email' => 'c@acme.test'])->assertStatus(200);
        // 3rd change exceeds the max of 2
        $this->postJson('/api/partner/email/change', ['flow_id' => $flow, 'email' => 'd@acme.test'])->assertStatus(429);
    }

    public function test_email_change_to_a_taken_address_does_not_hijack_it(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'someone@else.test']);
        [$flow] = $this->register(['email' => 'me@acme.test']);

        // uniform success response, but the code is NOT re-pointed to the taken address
        $this->postJson('/api/partner/email/change', ['flow_id' => $flow, 'email' => 'someone@else.test'])
            ->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertSame('me@acme.test', EmailVerificationCode::where('flow_id', $flow)->value('email'));
        $this->assertTrue(User::where('email', 'me@acme.test')->exists());
    }
}
