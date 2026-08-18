<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class FilamentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_panel_to_totp_login(): void
    {
        $this->get('/manage')->assertRedirect('/admin-login.html');
    }

    public function test_can_access_panel_requires_admin_role_mfa_and_active(): void
    {
        $panel = Filament::getPanel('admin');

        // student → no
        $student = User::factory()->create();
        UserRole::create(['user_id' => $student->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);
        $this->assertFalse($student->fresh()->canAccessPanel($panel));

        // admin role but NO mfa → no
        $noMfa = User::factory()->create();
        UserRole::create(['user_id' => $noMfa->id, 'role' => Role::ContentEditor, 'agency_id' => null, 'granted_at' => now()]);
        $this->assertFalse($noMfa->fresh()->canAccessPanel($panel));

        // admin role + mfa + active → yes
        $admin = User::factory()->create();
        UserRole::create(['user_id' => $admin->id, 'role' => Role::ContentEditor, 'agency_id' => null, 'granted_at' => now()]);
        $admin->forceFill([
            'mfa_secret' => (new Google2FA)->generateSecretKey(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $this->assertTrue($admin->fresh()->canAccessPanel($panel));
    }
}
