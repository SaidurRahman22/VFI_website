<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'a-good-long-passphrase';

    private function student(array $over = []): User
    {
        $u = User::factory()->create(array_merge(['password' => self::PW], $over));
        UserRole::create(['user_id' => $u->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);

        return $u->fresh();
    }

    public function test_verified_student_signs_in(): void
    {
        $u = $this->student(['email' => 'v@example.com']);

        $this->postJson('/api/login', ['email' => 'v@example.com', 'password' => self::PW])
            ->assertStatus(200)
            ->assertJsonPath('must_verify', false)
            ->assertJsonPath('user.email', 'v@example.com')
            ->assertJsonMissingPath('user.password');

        $this->assertAuthenticatedAs($u);
    }

    public function test_unverified_student_may_sign_in_but_is_flagged(): void
    {
        $this->student(['email' => 'p@example.com', 'email_verified_at' => null, 'status' => UserStatus::PendingVerification]);

        $this->postJson('/api/login', ['email' => 'p@example.com', 'password' => self::PW])
            ->assertStatus(200)
            ->assertJsonPath('must_verify', true);
    }

    public function test_wrong_password_and_unknown_account_are_identical(): void
    {
        $this->student(['email' => 'real@example.com']);

        $wrong = $this->postJson('/api/login', ['email' => 'real@example.com', 'password' => 'nope']);
        $unknown = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

        $wrong->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
        $unknown->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_valid_admin_credentials_are_refused_at_student_login(): void
    {
        $admin = User::factory()->create(['email' => 'boss@vfi.com', 'password' => self::PW]);
        UserRole::create(['user_id' => $admin->id, 'role' => Role::SuperAdmin, 'agency_id' => null, 'granted_at' => now()]);

        $this->postJson('/api/login', ['email' => 'boss@vfi.com', 'password' => self::PW])
            ->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertGuest();
    }

    public function test_suspended_student_cannot_sign_in(): void
    {
        $this->student(['email' => 's@example.com', 'status' => UserStatus::Suspended]);

        $this->postJson('/api/login', ['email' => 's@example.com', 'password' => self::PW])
            ->assertStatus(401);
        $this->assertGuest();
    }

    public function test_locked_account_gives_the_same_generic_failure(): void
    {
        $this->student(['email' => 'l@example.com', 'locked_until' => now()->addMinutes(10)]);

        $this->postJson('/api/login', ['email' => 'l@example.com', 'password' => self::PW])
            ->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_student_me_requires_a_student_session(): void
    {
        $this->getJson('/api/student/me')->assertStatus(401);

        $this->student(['email' => 'me@example.com']);
        $this->postJson('/api/login', ['email' => 'me@example.com', 'password' => self::PW])->assertStatus(200);

        $this->getJson('/api/student/me')->assertStatus(200)->assertJsonPath('user.email', 'me@example.com');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->student(['email' => 'out@example.com']);
        $this->postJson('/api/login', ['email' => 'out@example.com', 'password' => self::PW])->assertStatus(200);
        $this->getJson('/api/student/me')->assertStatus(200);

        $this->postJson('/api/student/logout')->assertStatus(200);
        $this->getJson('/api/student/me')->assertStatus(401);
        $this->assertGuest();
    }
}
