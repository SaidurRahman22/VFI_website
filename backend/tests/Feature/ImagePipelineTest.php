<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Blog;
use App\Models\Content\Event;
use App\Models\SiteContent;
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
        $u->forceFill(['mfa_secret' => (new Google2FA)->generateSecretKey(), 'mfa_enrolled_at' => now()])->save();

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

    /**
     * A picture used by a country page survives deleting the blog that shares it.
     *
     * Managed ids are the sha256 of the re-encoded bytes, so the same photograph
     * uploaded twice IS the same id. referenceCount() counted the four img_id
     * models and the flat `media` map and nothing else, so a country, region or
     * services image was invisible to it - and deleteIfUnreferenced() then
     * deleted a file that a live page was still painting.
     */
    public function test_an_image_a_country_page_uses_is_not_deleted_with_the_blog_that_shares_it(): void
    {
        $images = app(ImageService::class);

        // one file, referenced from a blog row AND from a country university card
        $id = '/storage/media/'.str_repeat('a', 64).'.jpg';
        Storage::disk('public')->put('media/'.str_repeat('a', 64).'.jpg', 'bytes');

        Blog::create(['title' => 'Campus tour', 'img_id' => $id]);
        SiteContent::query()->updateOrCreate(['key' => 'countries'], ['value' => [
            'uk' => ['universities' => [['name' => 'Leeds', 'img' => $id]]],
        ], 'version' => 1]);

        $this->assertSame(2, $images->referenceCount($id), 'the country card must be counted');

        // erasing the blog must NOT take the country page's picture with it
        Blog::query()->forceDelete();
        $images->deleteIfUnreferenced($id);

        Storage::disk('public')->assertExists('media/'.str_repeat('a', 64).'.jpg');
        $this->assertSame(1, $images->referenceCount($id));

        // and once the country stops using it too, it really does go
        SiteContent::query()->where('key', 'countries')->update(['value' => json_encode([])]);
        $images->deleteIfUnreferenced($id);
        Storage::disk('public')->assertMissing('media/'.str_repeat('a', 64).'.jpg');
    }
}
