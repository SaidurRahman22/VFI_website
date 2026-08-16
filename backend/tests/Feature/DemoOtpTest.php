<?php

namespace Tests\Feature;

use App\Models\AuthEvent;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo OTP is a temporary affordance so the product can be walked through
 * before a mail sender exists. These pin the two things that make it safe:
 * it is OFF unless explicitly configured, and its use is recorded.
 */
class DemoOtpTest extends TestCase
{
    use RefreshDatabase;

    private function issue(string $email = 'demo@vfi-fc.com'): string
    {
        return app(OtpService::class)->issue($email, null, 'signup_student', '127.0.0.1')['record']->flow_id;
    }

    public function test_it_is_disabled_by_default(): void
    {
        config(['auth.demo_otp' => '']);

        $out = app(OtpService::class)->verify($this->issue(), '123456');

        // the fixed code is just a wrong code when the feature is off
        $this->assertSame('invalid', $out['status']);
        $this->assertSame(0, AuthEvent::where('event', 'otp_demo_bypass')->count());
    }

    public function test_when_configured_the_fixed_code_verifies_and_is_audited(): void
    {
        config(['auth.demo_otp' => '123456']);

        $out = app(OtpService::class)->verify($this->issue(), '123456');

        $this->assertSame('ok', $out['status']);
        $this->assertSame(1, AuthEvent::where('event', 'otp_demo_bypass')->count());
    }

    public function test_the_real_code_still_works_while_the_bypass_is_on(): void
    {
        config(['auth.demo_otp' => '123456']);
        $issued = app(OtpService::class)->issue('demo2@vfi-fc.com', null, 'signup_student', '127.0.0.1');

        $out = app(OtpService::class)->verify($issued['record']->flow_id, $issued['code']);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(0, AuthEvent::where('event', 'otp_demo_bypass')->count());
    }

    public function test_a_wrong_code_is_still_rejected_while_the_bypass_is_on(): void
    {
        config(['auth.demo_otp' => '123456']);

        $this->assertSame('invalid', app(OtpService::class)->verify($this->issue(), '999999')['status']);
    }

    public function test_the_bypass_does_not_revive_a_consumed_flow(): void
    {
        config(['auth.demo_otp' => '123456']);
        $flow = $this->issue();

        $this->assertSame('ok', app(OtpService::class)->verify($flow, '123456')['status']);
        // single-use still holds — the fixed code cannot reopen it
        $this->assertSame('not_found', app(OtpService::class)->verify($flow, '123456')['status']);
    }
}
