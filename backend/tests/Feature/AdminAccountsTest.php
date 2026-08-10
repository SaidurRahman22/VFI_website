<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private AdminAccounts $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = app(AdminAccounts::class);
    }

    public function test_superadmin_bootstrap_is_sealed(): void
    {
        $this->accounts->createSuperAdmin('owner@vfi.test', 'strong-pass-1');
        $this->assertTrue(User::where('email', 'owner@vfi.test')->first()->isSuperAdmin());

        $this->expectException(\RuntimeException::class);
        $this->accounts->createSuperAdmin('other@vfi.test', 'strong-pass-2');   // sealed
    }

    public function test_create_superadmin_command_works_then_seals(): void
    {
        $this->artisan('admin:create-superadmin', ['email' => 'boot@vfi.test', '--password' => 'x-strong-pass'])
            ->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'boot@vfi.test']);

        // second run is sealed
        $this->artisan('admin:create-superadmin', ['email' => 'again@vfi.test', '--password' => 'x-strong-pass'])
            ->assertFailed();
    }

    public function test_last_superadmin_cannot_demote_or_delete_itself(): void
    {
        $sa = $this->accounts->createSuperAdmin('owner@vfi.test', 'strong-pass');

        $this->expectException(\RuntimeException::class);
        $this->accounts->revokeRole($sa, Role::SuperAdmin);
    }

    public function test_second_superadmin_allows_demotion(): void
    {
        $sa1 = $this->accounts->createSuperAdmin('owner@vfi.test', 'strong-pass');
        // promote a second one directly
        $sa2 = User::factory()->create();
        UserRole::create(['user_id' => $sa2->id, 'role' => Role::SuperAdmin, 'agency_id' => null, 'granted_at' => now()]);

        $this->accounts->revokeRole($sa1->fresh(), Role::SuperAdmin);   // now allowed
        $this->assertFalse($sa1->fresh()->isSuperAdmin());
    }

    public function test_invite_is_single_use_and_creates_admin(): void
    {
        $sa = $this->accounts->createSuperAdmin('owner@vfi.test', 'strong-pass');

        ['token' => $token] = $this->accounts->issueInvite($sa, 'editor@vfi.test', Role::ContentEditor);

        $user = $this->accounts->acceptInvite($token, 'Ed Itor', 'editor-strong-pass');
        $this->assertTrue($user->fresh()->hasRole(Role::ContentEditor));

        // single-use: second accept fails
        $this->expectException(\RuntimeException::class);
        $this->accounts->acceptInvite($token, 'Someone', 'another-pass');
    }

    public function test_non_superadmin_cannot_invite(): void
    {
        $editor = User::factory()->create();
        UserRole::create(['user_id' => $editor->id, 'role' => Role::ContentEditor, 'agency_id' => null, 'granted_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->accounts->issueInvite($editor->fresh(), 'x@vfi.test', Role::ContentEditor);
    }
}
