<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otp = app(OtpService::class);
    }

    public function test_wrong_code_increments_then_locks_and_destroys_after_five(): void
    {
        $issued = $this->otp->issue('a@b.com', null, 'signup_student', null);
        $flow = $issued['record']->flow_id;

        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame('invalid', $this->otp->verify($flow, '000000')['status']);
        }
        // 5th wrong attempt → locked + code destroyed
        $this->assertSame('locked', $this->otp->verify($flow, '000000')['status']);
        $this->assertTrue(EmailVerificationCode::where('flow_id', $flow)->first()->isConsumed());

        // even the correct code no longer works once destroyed
        $this->assertSame('not_found', $this->otp->verify($flow, $issued['code'])['status']);
    }

    public function test_correct_code_promotes_pending_user(): void
    {
        $u = User::factory()->unverified()->create(['status' => UserStatus::PendingVerification]);
        $issued = $this->otp->issue($u->email, $u->id, 'signup_student', null);

        $this->assertSame('ok', $this->otp->verify($issued['record']->flow_id, $issued['code'])['status']);

        $u->refresh();
        $this->assertSame(UserStatus::Active, $u->status);
        $this->assertNotNull($u->email_verified_at);

        // single-use: a second correct submission is rejected
        $this->assertSame('not_found', $this->otp->verify($issued['record']->flow_id, $issued['code'])['status']);
    }

    public function test_expired_code_is_rejected(): void
    {
        $issued = $this->otp->issue('a@b.com', null, 'signup_student', null);
        $issued['record']->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame('expired', $this->otp->verify($issued['record']->flow_id, $issued['code'])['status']);
    }

    public function test_issue_supersedes_prior_live_code(): void
    {
        $first = $this->otp->issue('a@b.com', null, 'signup_student', null);
        $second = $this->otp->issue('a@b.com', null, 'signup_student', null);

        $this->assertTrue($first['record']->fresh()->isConsumed());          // old one killed
        $this->assertSame('not_found', $this->otp->verify($first['record']->flow_id, $first['code'])['status']);
        $this->assertSame('ok', $this->otp->verify($second['record']->flow_id, $second['code'])['status']);
    }

    public function test_rotate_invalidates_old_code_and_resets_attempts(): void
    {
        $issued = $this->otp->issue('a@b.com', null, 'signup_student', null);
        $rec = $issued['record'];
        // simulate 3 prior failed attempts + a send >30s ago
        $rec->forceFill(['attempts_used' => 3, 'last_sent_at' => now()->subMinute()])->save();

        $rotated = $this->otp->rotate($rec, null);

        $this->assertSame(0, $rec->fresh()->attempts_used);                       // counter reset
        $this->assertNotSame($issued['code'], $rotated['code']);                  // new code (almost surely)
        $this->assertSame('invalid', $this->otp->verify($rec->flow_id, $issued['code'])['status']);  // old dead
        $this->assertSame('ok', $this->otp->verify($rec->flow_id, $rotated['code'])['status']);      // new works
    }

    public function test_rotate_is_blocked_inside_the_30s_window(): void
    {
        $issued = $this->otp->issue('a@b.com', null, 'signup_student', null);   // last_sent_at = now

        $this->expectException(\RuntimeException::class);
        $this->otp->rotate($issued['record'], null);
    }
}
