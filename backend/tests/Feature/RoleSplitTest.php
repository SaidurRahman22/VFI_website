<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Event;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class RoleSplitTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(Role $role): User
    {
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => $role, 'agency_id' => null, 'granted_at' => now()]);
        $u->forceFill(['mfa_secret' => (new Google2FA)->generateSecretKey(), 'mfa_enrolled_at' => now()])->save();

        return $u->fresh();
    }

    public function test_role_capability_helpers(): void
    {
        $this->assertTrue($this->withRole(Role::ContentEditor)->canEditContent());
        $this->assertTrue($this->withRole(Role::SuperAdmin)->canEditContent());
        $this->assertFalse($this->withRole(Role::StaffFinance)->canEditContent());
        $this->assertFalse($this->withRole(Role::StaffCounsellor)->canEditContent());

        $this->assertTrue($this->withRole(Role::SuperAdmin)->isOwner());
        $this->assertFalse($this->withRole(Role::ContentEditor)->isOwner());
    }

    public function test_content_policy_allows_editor_denies_finance(): void
    {
        $editor = $this->withRole(Role::ContentEditor);
        $finance = $this->withRole(Role::StaffFinance);

        $this->assertTrue(Gate::forUser($editor)->allows('viewAny', Event::class));
        $this->assertTrue(Gate::forUser($editor)->allows('create', Event::class));
        $this->assertFalse(Gate::forUser($finance)->allows('viewAny', Event::class));
        $this->assertFalse(Gate::forUser($finance)->allows('create', Event::class));

        // hard delete is owner-only even for a content_editor
        $e = Event::create(['title' => 'x']);
        $this->assertFalse(Gate::forUser($editor)->allows('forceDelete', $e));
        $this->assertTrue(Gate::forUser($this->withRole(Role::SuperAdmin))->allows('forceDelete', $e));
    }

    public function test_non_content_staff_cannot_edit_singletons(): void
    {
        $this->actingAs($this->withRole(Role::StaffFinance))
            ->putJson('/api/admin/content/singleton/settings', ['version' => 0, 'value' => ['brand' => 'HAX']])
            ->assertStatus(403);
    }

    public function test_content_editor_can_edit_singletons_but_not_pages(): void
    {
        $editor = $this->withRole(Role::ContentEditor);
        $this->actingAs($editor)
            ->putJson('/api/admin/content/singleton/settings', ['version' => 0, 'value' => ['brand' => 'VFI']])
            ->assertOk();
        $this->actingAs($editor)
            ->putJson('/api/admin/pages/about.html', ['enabled' => false])
            ->assertStatus(403);   // page-toggle is owner-only
    }
}
