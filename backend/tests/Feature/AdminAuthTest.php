<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $withMfa = false, Role $role = Role::StaffCounsellor): User
    {
        $user = User::factory()->create(['email' => 'admin@vfi.test']);  // factory password = 'password'
        UserRole::create(['user_id' => $user->id, 'role' => $role, 'agency_id' => null, 'granted_at' => now()]);

        if ($withMfa) {
            $secret = (new Google2FA())->generateSecretKey();
            $user->forceFill(['mfa_secret' => $secret, 'mfa_enrolled_at' => now()])->save();
        }

        return $user->fresh();
    }

    private function otp(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    /** HARD GATE: no admin route is reachable without an authenticated session. */
    public function test_unauthenticated_admin_route_is_blocked(): void
    {
        $this->getJson('/api/admin/me')->assertStatus(401);
        $this->postJson('/api/admin/logout')->assertStatus(401);
    }

    public function test_password_alone_cannot_reach_panel(): void
    {
        $this->admin(withMfa: true);

        // password OK → pending TOTP, NOT authenticated
        $this->postJson('/api/admin/login', ['email' => 'admin@vfi.test', 'password' => 'password'])
            ->assertOk()->assertJson(['step' => 'totp']);

        $this->assertGuest('web');
        $this->getJson('/api/admin/me')->assertStatus(401);   // still locked out
    }

    public function test_totp_completes_login(): void
    {
        $user = $this->admin(withMfa: true);

        $this->postJson('/api/admin/login', ['email' => 'admin@vfi.test', 'password' => 'password'])
            ->assertOk()->assertJson(['step' => 'totp']);

        $this->postJson('/api/admin/login/totp', ['code' => $this->otp($user->mfa_secret)])
            ->assertOk()->assertJson(['step' => 'done']);

        $this->assertAuthenticated('web');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('email', 'admin@vfi.test');
    }

    public function test_first_time_enrolment_then_access(): void
    {
        $this->admin(withMfa: false);

        $this->postJson('/api/admin/login', ['email' => 'admin@vfi.test', 'password' => 'password'])
            ->assertOk()->assertJson(['step' => 'enroll']);

        $enroll = $this->postJson('/api/admin/mfa/enroll')->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_uri', 'qr_svg'])->json();

        $this->postJson('/api/admin/mfa/confirm', ['code' => $this->otp($enroll['secret'])])
            ->assertOk()->assertJson(['step' => 'done']);

        $this->assertAuthenticated('web');
        $this->assertNotNull(User::where('email', 'admin@vfi.test')->first()->mfa_enrolled_at);
    }

    public function test_wrong_password_is_rejected_and_counts_failure(): void
    {
        $user = $this->admin(withMfa: true);

        $this->postJson('/api/admin/login', ['email' => 'admin@vfi.test', 'password' => 'WRONG'])
            ->assertStatus(401);

        $this->assertSame(1, $user->fresh()->failed_login_count);
        $this->assertGuest('web');
    }

    public function test_non_admin_credentials_do_not_reveal_validity(): void
    {
        // valid password but a non-admin (student) role
        $this->admin(withMfa: false, role: Role::Student);

        $this->postJson('/api/admin/login', ['email' => 'admin@vfi.test', 'password' => 'password'])
            ->assertStatus(401);   // same shape as a bad password — no scope leak
    }

    public function test_reused_totp_code_is_rejected(): void
    {
        $user = $this->admin(withMfa: true);
        $code = $this->otp($user->mfa_secret);

        $this->postJson('/api/admin/login', ['email' => 'admin@vfi.test', 'password' => 'password']);
        $this->postJson('/api/admin/login/totp', ['code' => $code])->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();

        // same code again → replay blocked
        $this->postJson('/api/admin/login', ['email' => 'admin@vfi.test', 'password' => 'password']);
        $this->postJson('/api/admin/login/totp', ['code' => $code])->assertStatus(401);
    }
}
