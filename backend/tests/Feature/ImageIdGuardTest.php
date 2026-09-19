<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Photo;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use App\Support\ImageIdGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One allow-list, applied everywhere an image id can be written.
 *
 * THE BUG THIS EXISTS FOR. The list lived as a private method on
 * AdminContentController, so it guarded the singleton editors and nothing else.
 * The other two write paths validated an image id as `string|max:255` — a SIZE
 * limit, which is not a decision about what may be fetched:
 *
 *   POST/PUT /api/admin/content/{collection}   img_id on events, blogs, news,
 *                                              photos → painted by events.html,
 *                                              news.html, the blog list and
 *                                              blog-post.html
 *   PUT  /api/admin/media/slot/{key}           the home page hero and bands
 *
 * Either one accepted `https://evil.example/beacon.png`, and the public page
 * then put it in a `background-image`, so every anonymous visitor's browser
 * announced its IP, User-Agent and Referer to a third party on page load.
 * Nothing executes — a stylesheet will not run script — so this is a privacy
 * and tracking boundary, and it was open.
 *
 * WHAT IS ASSERTED, and why it is asserted this way. Not "the guard works" —
 * AdminGroupedImageFieldTest already covered that for the path that had one.
 * What is asserted is that the SAME value is refused at every ADMIN API write
 * site. A per-site test would keep passing the day someone adds another
 * endpoint with its own private copy of the rules, which is precisely how this
 * started.
 *
 * TWO WRITERS ARE DELIBERATELY OUT OF SCOPE, and saying so is the point — an
 * earlier draft of this file claimed "every write site" and was wrong:
 *
 *   POST /api/admin/backup/import  (BackupService::restore)   owner-only
 *   php artisan content:import     (ImportContent)            shell-only
 *
 * Both write img_id and the media map straight from a payload. Neither is
 * guarded, on purpose: a restore that silently dropped the ids it did not
 * recognise would hand back a site with its pictures missing and no error, and
 * both require a trust level (the owner, or a shell on the box) at which the
 * database is already writable directly. The browser mirror in js/render.js is
 * what covers what they can produce.
 */
class ImageIdGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Values that must never reach a public page, and what each one would do.
     *
     * Kept in step with AdminGroupedImageFieldTest's list on purpose: the claim
     * being tested is that the three endpoints agree, so they have to be asked
     * the same questions.
     *
     * @return array<string, string>
     */
    private function refused(): array
    {
        return [
            'a remote beacon' => 'https://evil.example/beacon.png',
            'a protocol-relative host' => '//evil.example/beacon.png',
            'plain http' => 'http://evil.example/beacon.png',
            'not a content hash' => '/storage/media/notahash.jpg',
            'a traversal out of the bundled folder' => 'assets/img/../../.env',
            // Never re-encoded by the pipeline, and a script host in a browser.
            'an svg' => 'assets/img/logo.svg',
            // The retired admin.html wrote these; they resolve in one editor's
            // own IndexedDB and nowhere else, least of all on a visitor's page.
            'a legacy browser-store key' => 'img_1699999999',
            'an inline data url' => 'data:image/png;base64,iVBORw0KGgo=',
            'a cache-buster query' => 'assets/img/campus.jpg?v=2',
            'an upper-case name' => 'assets/img/Campus.JPG',
            'a file outside assets/img' => 'storage/app/.env',
            // The CSS-injection shape the frontend was fixed for. Refusing it
            // at the door as well is the point of defence in depth: the browser
            // guard is for rows that never came through a controller.
            'a second url smuggled into the token' => 'assets/img/a.jpg), url(https://evil.example/b.png',
        ];
    }

    /** The two shapes that are real, and must keep working. */
    private function accepted(): array
    {
        return [
            'a managed upload' => '/storage/media/'.str_repeat('a1', 32).'.jpg',
            'a bundled photo' => 'assets/img/campus.jpg',
            'a bundled photo with a hyphen' => 'assets/img/students-group.jpg',
        ];
    }

    private function staff(Role $role = Role::ContentEditor): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => $role->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    // ------------------------------------------------------------- the list

    public function test_the_guard_refuses_every_value_that_could_leave_this_origin(): void
    {
        foreach ($this->refused() as $why => $id) {
            $this->assertFalse(ImageIdGuard::isUsable($id), "{$why} must be refused");
        }
    }

    public function test_the_guard_accepts_the_two_shapes_the_site_actually_produces(): void
    {
        foreach ($this->accepted() as $what => $id) {
            $this->assertTrue(ImageIdGuard::isUsable($id), "{$what} must be accepted");
        }
    }

    // ------------------------------------------ the same answer at every door

    /**
     * The collection editor. It had no allow-list at all, so each of these was
     * stored and then painted on a public page.
     */
    public function test_the_collection_editor_refuses_the_same_values(): void
    {
        $this->actingAs($this->staff());

        foreach ($this->refused() as $why => $id) {
            $this->postJson('/api/admin/content/photos', [
                'img_id' => $id,
                'caption' => 'Looks ordinary in the list',
                'alt' => 'and that is the problem',
            ])->assertStatus(422)->assertJsonValidationErrors('img_id');
        }

        $this->assertSame(0, Photo::query()->withTrashed()->count(), 'not one of those may have been stored');
    }

    /**
     * An EDIT is a separate door from a create, and the one that matters more:
     * a row already on the site is the one an attacker would rather repoint
     * than add, because nobody is looking at it.
     */
    public function test_editing_an_existing_row_cannot_repoint_it_off_site(): void
    {
        $photo = Photo::create(['caption' => 'Campus', 'img_id' => 'assets/img/campus.jpg', 'position' => 1]);
        $this->actingAs($this->staff());

        $this->putJson("/api/admin/content/photos/{$photo->id}", [
            'img_id' => 'https://evil.example/beacon.png',
            'caption' => 'Campus',
            'alt' => '',
        ])->assertStatus(422)->assertJsonValidationErrors('img_id');

        $this->assertSame('assets/img/campus.jpg', $photo->fresh()?->img_id);
    }

    /** The home-page media slots. Same list, same refusal. */
    public function test_the_media_slot_endpoint_refuses_the_same_values(): void
    {
        $this->actingAs($this->staff());

        foreach ($this->refused() as $why => $id) {
            $this->putJson('/api/admin/media/slot/hero', ['version' => 0, 'imgId' => $id])
                ->assertStatus(422)->assertJsonValidationErrors('imgId');
        }

        $this->assertNull(SiteContent::value('media'), 'a refused slot write must leave no row behind');
    }

    /** And all three still accept a real id, or the guard is just an outage. */
    public function test_every_door_still_accepts_a_bundled_photo(): void
    {
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/photos', [
            'img_id' => 'assets/img/campus.jpg', 'caption' => 'Campus', 'alt' => 'A campus',
        ])->assertCreated()->assertJsonPath('item.img_id', 'assets/img/campus.jpg');

        $this->putJson('/api/admin/media/slot/hero', ['version' => 0, 'imgId' => 'assets/img/campus.jpg'])
            ->assertOk()->assertJsonPath('media.hero', 'assets/img/campus.jpg');
    }

    // ---------------------------------------------- the university card image

    /**
     * universityPage.seasons[].image was declared `text`, so it went through no
     * allow-list at all while sitting in the very controller that had one — and
     * it is painted on university.html, which anyone can open. Its hint used to
     * say "or a full https:// address", so this was not even a lapse, it was
     * documented.
     *
     * It is `asset` now, a different shape from an image id: it holds the disk
     * key Filament's upload writes, which assetUrl() prefixes with /storage/ on
     * the way out.
     */
    public function test_the_university_card_image_cannot_be_pointed_off_site(): void
    {
        $this->actingAs($this->staff());

        foreach (['https://evil.example/beacon.png', '//evil.example/b.png', 'media/../../.env', 'http://x/y.jpg'] as $bad) {
            $this->putJson('/api/admin/content/singleton/universityPage', [
                'version' => 0,
                'value' => ['seasons' => [['key' => 'fall', 'month' => 'September', 'note' => '', 'image' => $bad]]],
            ])->assertStatus(422);
        }

        $this->assertNull(SiteContent::value('universityPage'), 'not one of those may have been stored');
    }

    /** And the shape the upload screen actually writes still saves. */
    public function test_the_university_card_image_accepts_an_uploaded_file(): void
    {
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/universityPage', [
            'version' => 0,
            'value' => ['seasons' => [['key' => 'fall', 'month' => 'September', 'note' => '', 'image' => 'media/universities/intakes/fall.jpg']]],
        ])->assertOk();

        $this->assertSame(
            'media/universities/intakes/fall.jpg',
            SiteContent::value('universityPage')['seasons'][0]['image'],
        );
    }

    // ------------------------------------------------------------------ trim

    /**
     * A pasted id often carries a space, and `api/admin/content*` is exempt
     * from Laravel's TrimStrings (bootstrap/app.php keeps "" meaningful there).
     * The guard trims before judging, so the save is accepted — and what gets
     * STORED has to be trimmed too, or the browser's own allow-list refuses the
     * value on the way out and the picture silently does not appear, with the
     * row looking perfectly correct in the editor.
     */
    public function test_a_pasted_id_with_a_space_is_stored_without_it(): void
    {
        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/photos', [
            'img_id' => "  assets/img/campus.jpg\t",
            'caption' => 'Campus',
            'alt' => 'A campus',
        ])->assertCreated();

        $this->assertSame('assets/img/campus.jpg', Photo::query()->find($res->json('item.id'))?->img_id);
    }

    /** Clearing a picture stays possible; empty is not an attack. */
    public function test_an_empty_id_is_allowed_because_a_row_may_have_no_picture(): void
    {
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/photos', [
            'img_id' => '', 'caption' => 'No picture yet', 'alt' => '',
        ])->assertCreated()->assertJsonPath('item.img_id', '');

        $this->putJson('/api/admin/media/slot/hero', ['version' => 0, 'imgId' => null])->assertOk();
    }
}
