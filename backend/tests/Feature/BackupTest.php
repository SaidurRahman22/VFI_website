<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Event;
use App\Models\ContentAuditLog;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function withRole(Role $role): User
    {
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => $role, 'agency_id' => null, 'granted_at' => now()]);
        $u->forceFill(['mfa_secret' => (new Google2FA)->generateSecretKey(), 'mfa_enrolled_at' => now()])->save();

        return $u->fresh();
    }

    public function test_owner_can_export_content(): void
    {
        Event::create(['title' => 'Launch']);
        $this->actingAs($this->withRole(Role::SuperAdmin))
            ->getJson('/api/admin/backup/export')
            ->assertStatus(200)
            ->assertJsonPath('version', 1)
            ->assertJsonPath('content.events.0.title', 'Launch')
            ->assertHeader('Content-Disposition', 'attachment; filename="vfi-backup.json"');
    }

    public function test_content_editor_cannot_export_or_import(): void
    {
        $editor = $this->withRole(Role::ContentEditor);
        $this->actingAs($editor)->getJson('/api/admin/backup/export')->assertStatus(403);
        $this->actingAs($editor)->postJson('/api/admin/backup/import', ['content' => ['events' => []]])
            ->assertStatus(403);
    }

    public function test_import_replaces_content_and_snapshots_first(): void
    {
        Event::create(['title' => 'OLD-should-vanish']);

        $payload = ['content' => [
            'events' => [['id' => 'e1', 'title' => 'Imported One'], ['id' => 'e2', 'title' => 'Imported Two']],
            'settings' => ['brand' => 'VFI'],
        ]];

        $res = $this->actingAs($this->withRole(Role::SuperAdmin))
            ->postJson('/api/admin/backup/import', ['payload' => $payload])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        // old content wiped, imported content present + ordered
        $this->assertDatabaseMissing('events', ['title' => 'OLD-should-vanish']);
        $titles = Event::orderBy('position')->pluck('title')->all();
        $this->assertSame(['Imported One', 'Imported Two'], $titles);
        $this->assertSame('VFI', SiteContent::value('settings')['brand']);

        // a pre-restore snapshot was written that still holds the OLD content
        $snap = $res->json('snapshot');
        Storage::disk('local')->assertExists($snap);
        $this->assertStringContainsString('OLD-should-vanish', Storage::disk('local')->get($snap));

        // exactly one 'restore' audit row (per-row mutations muted)
        $this->assertSame(1, ContentAuditLog::where('action', 'restore')->count());
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $owner = $this->withRole(Role::SuperAdmin);

        // no recognised keys
        $this->actingAs($owner)->postJson('/api/admin/backup/import', ['payload' => ['nonsense' => 1]])
            ->assertStatus(422);

        // a collection that isn't a list
        $this->actingAs($owner)->postJson('/api/admin/backup/import', ['payload' => ['content' => ['events' => 'oops']]])
            ->assertStatus(422);
    }

    public function test_export_import_round_trips(): void
    {
        Event::create(['title' => 'RT-A']);
        Event::create(['title' => 'RT-B']);
        SiteContent::updateOrCreate(['key' => 'settings'], ['value' => ['brand' => 'RoundTrip'], 'version' => 1]);
        $owner = $this->withRole(Role::SuperAdmin);

        $export = $this->actingAs($owner)->getJson('/api/admin/backup/export')->json();
        $before = Event::count();

        $this->actingAs($owner)->postJson('/api/admin/backup/import', ['payload' => $export])->assertStatus(200);

        $this->assertSame($before, Event::count());
        $this->assertSame('RoundTrip', SiteContent::value('settings')['brand']);
    }
}
