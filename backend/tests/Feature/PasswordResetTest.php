<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\ResetMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $over = []): User
    {
        $u = User::factory()->create(array_merge(['password' => 'old-password-123'], $over));
        UserRole::create(['user_id' => $u->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);

        return $u->fresh();
    }

    public function test_request_for_student_sends_link_and_stores_hashed_token(): void
    {
        Mail::fake();
        $u = $this->student(['email' => 'r@example.com']);

        $this->postJson('/api/password/reset', ['email' => 'r@example.com'])->assertStatus(202);

        Mail::assertSent(ResetMail::class);
        $token = PasswordResetToken::where('user_id', $u->id)->firstOrFail();
        $this->assertNull($token->consumed_at);
        // stored hashed, not raw (64-hex sha256)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token->token_hash);
    }

    public function test_request_is_enumeration_safe(): void
    {
        Mail::fake();
        $this->student(['email' => 'known@example.com']);

        $known = $this->postJson('/api/password/reset', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/password/reset', ['email' => 'nobody@example.com']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json('message'), $unknown->json('message'));   // identical response
        $this->assertSame(0, PasswordResetToken::whereHas('user', fn ($q) => $q->where('email', 'nobody@example.com'))->count());
    }

    public function test_admin_email_gets_no_student_reset(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['email' => 'boss@vfi.com']);
        UserRole::create(['user_id' => $admin->id, 'role' => Role::SuperAdmin, 'agency_id' => null, 'granted_at' => now()]);

        $this->postJson('/api/password/reset', ['email' => 'boss@vfi.com'])->assertStatus(202);
        Mail::assertNotSent(ResetMail::class);
    }

    public function test_full_reset_changes_password_and_revokes_sessions(): void
    {
        $u = $this->student(['email' => 'full@example.com']);
        // two live sessions in the DB store
        DB::table('sessions')->insert([
            ['id' => 'sessA', 'user_id' => $u->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
            ['id' => 'sessB', 'user_id' => $u->id, 'ip_address' => '2.2.2.2', 'user_agent' => 'y', 'payload' => '', 'last_activity' => time()],
        ]);
        $oldRemember = $u->remember_token;

        $svc = app(PasswordResetService::class);
        $raw = $svc->request($u, null)['token'];

        $this->postJson('/api/password/reset/submit', [
            'token' => $raw, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertStatus(200)->assertJsonPath('ok', true);

        $u->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $u->password));   // new password active
        $this->assertFalse(Hash::check('old-password-123', $u->password));    // old dead
        $this->assertSame(0, DB::table('sessions')->where('user_id', $u->id)->count());  // all sessions gone
        $this->assertNotSame($oldRemember, $u->remember_token);               // remember cookie invalidated
    }

    public function test_token_is_single_use(): void
    {
        $u = $this->student();
        $svc = app(PasswordResetService::class);
        $raw = $svc->request($u, null)['token'];

        $ok = fn () => $this->postJson('/api/password/reset/submit', [
            'token' => $raw, 'password' => 'another-good-password', 'password_confirmation' => 'another-good-password',
        ]);

        $ok()->assertStatus(200);
        $ok()->assertStatus(422);   // reused → dead
    }

    public function test_new_request_supersedes_the_previous_token(): void
    {
        $u = $this->student();
        $svc = app(PasswordResetService::class);
        $first = $svc->request($u, null)['token'];
        $svc->request($u, null);   // supersedes the first

        $this->postJson('/api/password/reset/submit', [
            'token' => $first, 'password' => 'yet-another-password', 'password_confirmation' => 'yet-another-password',
        ])->assertStatus(422);
    }

    public function test_expired_token_is_rejected(): void
    {
        $u = $this->student();
        $svc = app(PasswordResetService::class);
        $issued = $svc->request($u, null);
        $issued['record']->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/password/reset/submit', [
            'token' => $issued['token'], 'password' => 'expired-flow-password', 'password_confirmation' => 'expired-flow-password',
        ])->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $u = $this->student();
        $raw = app(PasswordResetService::class)->request($u, null)['token'];

        $this->postJson('/api/password/reset/submit', [
            'token' => $raw, 'password' => 'password-one', 'password_confirmation' => 'password-two',
        ])->assertStatus(422);
    }
}
