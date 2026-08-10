<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\EmailVerificationCode;
use App\Models\PasswordResetToken;
use App\Models\TermsAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentAuthDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_verification_status_and_policy(): void
    {
        $this->assertTrue(UserStatus::PendingVerification->canStudentSignIn());
        $this->assertTrue(UserStatus::Active->canStudentSignIn());
        $this->assertFalse(UserStatus::Suspended->canStudentSignIn());
        $this->assertFalse(UserStatus::PendingVerification->canSignIn());  // admin bar unchanged
    }

    public function test_user_carries_phone_and_pending_status(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['phone' => '+880171234567', 'status' => UserStatus::PendingVerification])->save();

        $u->refresh();
        $this->assertSame('+880171234567', $u->phone);
        $this->assertSame(UserStatus::PendingVerification, $u->status);
    }

    public function test_terms_acceptance_is_stored(): void
    {
        $u = User::factory()->create();
        $t = TermsAcceptance::create([
            'user_id' => $u->id, 'document' => 'terms', 'version' => '2026-08',
            'accepted_at' => now(), 'ip' => '203.0.113.5', 'user_agent' => 'UA',
        ]);
        $this->assertTrue($t->user->is($u));
        $this->assertDatabaseHas('terms_acceptances', ['user_id' => $u->id, 'version' => '2026-08']);
    }

    public function test_email_verification_code_lifecycle_helpers(): void
    {
        $code = EmailVerificationCode::create([
            'flow_id' => (string) Str::uuid(), 'email' => 'a@b.com',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
            'attempts_used' => 0, 'max_attempts' => 5,
        ]);

        $this->assertTrue($code->isLive());
        $this->assertTrue(Hash::check('123456', $code->code_hash));   // stored hashed, verifiable

        $code->forceFill(['attempts_used' => 5])->save();
        $this->assertTrue($code->attemptsExhausted());
        $this->assertFalse($code->isLive());

        $code->forceFill(['attempts_used' => 0, 'expires_at' => now()->subMinute()])->save();
        $this->assertTrue($code->isExpired());
        $this->assertFalse($code->isLive());
    }

    public function test_reset_token_single_use_and_supersede(): void
    {
        $u = User::factory()->create();
        $t = PasswordResetToken::create([
            'user_id' => $u->id, 'token_hash' => hash('sha256', 'raw-token'),
            'requested_for_email' => $u->email, 'expires_at' => now()->addMinutes(45),
        ]);

        $this->assertTrue($t->isLive());
        $t->markConsumed('203.0.113.9');
        $t->refresh();
        $this->assertFalse($t->isLive());
        $this->assertSame('reset', $t->invalidated_by);
        $this->assertSame('203.0.113.9', $t->consumed_ip);
    }
}
