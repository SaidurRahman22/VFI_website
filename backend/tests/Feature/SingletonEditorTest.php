<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ContentAuditLog;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SingletonEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Role $role = Role::ContentEditor): User
    {
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => $role, 'agency_id' => null, 'granted_at' => now()]);
        $u->forceFill(['mfa_secret' => (new Google2FA())->generateSecretKey(), 'mfa_enrolled_at' => now()])->save();

        return $u->fresh();
    }

    public function test_unauthenticated_is_blocked(): void
    {
        $this->getJson('/api/admin/content/singleton/settings')->assertStatus(401);
    }

    public function test_show_returns_value_and_version(): void
    {
        SiteContent::create(['key' => 'countries', 'value' => ['usa' => ['title' => 'USA']], 'version' => 3]);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/content/singleton/countries')
            ->assertOk()
            ->assertJsonPath('version', 3)
            ->assertJsonPath('value.usa.title', 'USA');
    }

    public function test_update_with_correct_version_saves_and_bumps_and_audits(): void
    {
        SiteContent::create(['key' => 'servicesPage', 'value' => ['heroTitle' => 'Old'], 'version' => 1]);

        $this->actingAs($this->admin())
            ->putJson('/api/admin/content/singleton/servicesPage', [
                'version' => 1,
                'value' => ['heroTitle' => 'New Title'],
            ])
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('value.heroTitle', 'New Title');

        $this->assertSame('New Title', SiteContent::value('servicesPage')['heroTitle']);
        $this->assertSame(1, ContentAuditLog::where('entity', 'site_content')->where('action', 'update')->count());
    }

    public function test_stale_version_is_rejected_409_and_not_clobbered(): void
    {
        SiteContent::create(['key' => 'partnerPage', 'value' => ['heroTitle' => 'Current'], 'version' => 5]);

        $this->actingAs($this->admin())
            ->putJson('/api/admin/content/singleton/partnerPage', [
                'version' => 4,   // stale
                'value' => ['heroTitle' => 'Should NOT win'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('currentVersion', 5);

        $this->assertSame('Current', SiteContent::value('partnerPage')['heroTitle']);  // survived
    }

    public function test_disallowed_key_is_404(): void
    {
        // 'pages' has its own dedicated endpoint (P3-D), not this editor
        $this->actingAs($this->admin())
            ->getJson('/api/admin/content/singleton/pages')->assertStatus(404);
    }

    public function test_empty_value_round_trips_faithfully(): void
    {
        SiteContent::create(['key' => 'countries', 'value' => ['usa' => ['x' => 1]], 'version' => 1]);

        $this->actingAs($this->admin())
            ->putJson('/api/admin/content/singleton/countries', ['version' => 1, 'value' => []])
            ->assertOk();

        $this->assertSame([], SiteContent::value('countries'));   // cleared → [] (fall-through), not null
    }
}
