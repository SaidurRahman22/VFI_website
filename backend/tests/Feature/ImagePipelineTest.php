<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Event;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function editor(Role $role = Role::ContentEditor): User
    {
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => $role, 'agency_id' => null, 'granted_at' => now()]);
        $u->forceFill(['mfa_secret' => (new Google2FA())->generateSecretKey(), 'mfa_enrolled_at' => now()])->save();

        return $u->fresh();
    }

    public function test_real_image_uploads_and_is_reencoded(): void
    {
        $res = $this->actingAs($this->editor())
            ->postJson('/api/admin/media', ['file' => UploadedFile::fake()->image('photo.png', 2000, 1500)])
            ->assertStatus(201);

        $id = $res->json('imgId');
        $this->assertStringStartsWith('/storage/media/', $id);
        $this->assertStringEndsWith('.jpg', $id);              // re-encoded to jpeg
        Storage::disk('public')->assertExists('media/'.basename($id));
    }

    public function test_fake_image_bytes_are_rejected_by_magic_byte_check(): void
    {
        // passes the mime rule (declared image/jpeg) but the bytes are garbage
        $fake = UploadedFile::fake()->createWithContent('x.jpg', 'this is not an image');
        $this->actingAs($this->editor())
            ->postJson('/api/admin/media', ['file' => $fake])
            ->assertStatus(422);
    }

    public function test_non_content_staff_cannot_upload(): void
    {
        $this->actingAs($this->editor(Role::StaffFinance))
            ->postJson('/api/admin/media', ['file' => UploadedFile::fake()->image('x.png')])
            ->assertStatus(403);
    }

    public function test_reference_counted_deletion(): void
    {
        $svc = app(ImageService::class);
        $id = $svc->store(UploadedFile::fake()->image('a.png', 300, 200));
        Storage::disk('public')->assertExists('media/'.basename($id));

        Event::create(['title' => 'E1', 'img_id' => $id]);
        $e2 = Event::create(['title' => 'E2', 'img_id' => $id]);

        $svc->deleteIfUnreferenced($id);                        // 2 refs → keep
        Storage::disk('public')->assertExists('media/'.basename($id));

        Event::where('title', 'E1')->forceDelete();
        $e2->forceDelete();
        $svc->deleteIfUnreferenced($id);                        // 0 refs → delete
        Storage::disk('public')->assertMissing('media/'.basename($id));
    }

    public function test_path_style_bundled_asset_is_never_deleted(): void
    {
        $svc = app(ImageService::class);
        // no exception, no-op — must never touch a bundled static file
        $svc->deleteIfUnreferenced('assets/img/city-uk.jpg');
        $svc->deleteIfUnreferenced('https://cdn.example/x.jpg');
        $this->assertFalse($svc->isManaged('assets/img/city-uk.jpg'));
    }

    public function test_set_media_slot_replaces_and_deletes_unreferenced_old(): void
    {
        $svc = app(ImageService::class);
        $a = $svc->store(UploadedFile::fake()->image('a.png', 200, 200));
        $b = $svc->store(UploadedFile::fake()->image('b.png', 320, 240));  // different dims → different hash
        $this->assertNotSame($a, $b);

        $svc->setMedia('hero', $a);
        $svc->setMedia('hero', $b);   // replace → old A unreferenced → deleted

        Storage::disk('public')->assertMissing('media/'.basename($a));
        Storage::disk('public')->assertExists('media/'.basename($b));
    }
}
