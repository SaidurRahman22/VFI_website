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

class PageVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => $role, 'agency_id' => null, 'granted_at' => now()]);
        $u->forceFill(['mfa_secret' => (new Google2FA())->generateSecretKey(), 'mfa_enrolled_at' => now()])->save();

        return $u->fresh();
    }

    public function test_unauthenticated_is_blocked(): void
    {
        $this->getJson('/api/admin/pages')->assertStatus(401);
    }

    public function test_owner_can_toggle_and_it_is_audited(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin))
            ->putJson('/api/admin/pages/about.html', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertFalse(SiteContent::value('pages')['about.html']);
        $this->assertSame(1, ContentAuditLog::where('action', 'toggle_page')->where('entity_id', 'about.html')->count());

        // reflected in the public bundle (index the dotted key directly)
        $pages = $this->getJson('/api/content/bundle')->json('pages');
        $this->assertFalse($pages['about.html']);
    }

    public function test_content_editor_cannot_toggle_pages(): void
    {
        $this->actingAs($this->user(Role::ContentEditor))
            ->putJson('/api/admin/pages/about.html', ['enabled' => false])
            ->assertStatus(403);
        $this->actingAs($this->user(Role::ContentEditor))
            ->getJson('/api/admin/pages')->assertStatus(403);
    }

    public function test_signin_and_locked_pages_cannot_be_disabled(): void
    {
        $owner = $this->user(Role::SuperAdmin);
        $this->actingAs($owner)->putJson('/api/admin/pages/login.html', ['enabled' => false])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/admin/pages/vfi-partner-login.html', ['enabled' => false])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/admin/pages/index.html', ['enabled' => false])->assertStatus(422);
    }

    public function test_unknown_filename_is_rejected(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin))
            ->putJson('/api/admin/pages/evil.html', ['enabled' => false])
            ->assertStatus(422);
        $this->assertNull(SiteContent::value('pages'));   // nothing written
    }

    public function test_index_lists_the_catalogue(): void
    {
        $res = $this->actingAs($this->user(Role::SuperAdmin))->getJson('/api/admin/pages')->assertOk();
        $files = collect($res->json('pages'))->pluck('file');
        $this->assertTrue($files->contains('about.html'));
        $this->assertTrue($files->contains('study-in-usa.html'));
    }
}
