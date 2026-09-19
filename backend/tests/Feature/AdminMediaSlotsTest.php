<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The image slots for the page sections that are built into the markup — the
 * hero, the four service orbs, the Multi Country collage, the two partner-page
 * visuals.
 *
 * Until now the media map could be WRITTEN and never read: there was an upload
 * endpoint and a set-one-slot endpoint and nothing that answered what a slot
 * currently holds. No screen could therefore show an editor the picture they
 * were about to replace, which is the whole of this endpoint's job.
 *
 * What is tested harder than the happy path, and why:
 *
 *   THE KEY WAS REQUEST INPUT WITH NOTHING BEHIND IT. setSlot took the slot key
 *   straight from the URL and handed it to ImageService::setMedia, so any key
 *   at all could be written into the map. No page would ever render it, but it
 *   counted as a reference to the image it named, and reference counting is
 *   what decides whether an upload may be deleted from disk — an invented key
 *   pinned an orphaned file there permanently. The refusal is asserted along
 *   with the map staying untouched, because a 422 that still wrote would look
 *   identical from the outside.
 *
 *   ROLE, not just session. The route group only proves an admin session with
 *   TOTP. Partner-ops staff hold such a session and must not be able to change
 *   the pictures on the home page.
 *
 *   THE SCHEMA IS THE SCREEN. The console renders a tile per slot from what the
 *   server declares, so a slot missing from the schema is a picture nobody can
 *   ever change again. The ten keys production actually stores are written out
 *   here and checked against the schema rather than counted, so dropping one
 *   fails with its name.
 *
 *   READ AND WRITE MUST AGREE. The only previous coverage of setMedia called
 *   the service directly (ImagePipelineTest). Nothing proved the route writes
 *   the same map this reader reads, which is exactly the kind of gap that ends
 *   with an editor's upload disappearing on reload.
 *
 *   TEN SLOTS, ONE ROW. Setting the hero rewrites the whole map, including the
 *   nine slots the editor never touched. So two editors on this screen at once
 *   were a straightforward way to lose a picture: the second save carried the
 *   map as it was before the first, and the first editor's slot went quietly
 *   back to what it used to hold. Nothing on screen would have said so. The
 *   save now quotes the version it read, and the refusal is asserted together
 *   with the other editor's slot still standing — a 409 that had already
 *   written would look the same from the outside.
 */
class AdminMediaSlotsTest extends TestCase
{
    use RefreshDatabase;

    /** Every slot key live production holds in the `media` row. */
    private const PRODUCTION_KEYS = [
        'hero', 'students', 'partners', 'franchisees', 'universities',
        'collage1', 'collage2', 'collage3', 'partnerHero', 'partnerApp',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
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

    /**
     * A managed image id the allow-list accepts, named so the assertions below
     * still read as English.
     *
     * The literals here used to be `/storage/media/new-hero.jpg` and the like.
     * ImageIdGuard refuses those now - a managed id is the sha256 of the
     * re-encoded bytes, and a request the validator throws out cannot prove
     * anything about the version conflict or the slot allow-list each of these
     * tests is actually about.
     */
    private function imgId(string $name): string
    {
        return '/storage/media/'.hash('sha256', $name).'.jpg';
    }

    // ---------------------------------------------------------------- access

    public function test_the_slots_need_an_admin_session(): void
    {
        $this->getJson('/api/admin/media/slots')->assertStatus(401);
        $this->putJson('/api/admin/media/slot/hero', ['version' => 0, 'imgId' => null])
            ->assertStatus(401);
    }

    /**
     * Partner-ops staff hold an admin session, so the route group admits them.
     * The per-handler ability check is what must refuse them.
     *
     * The body is complete and correct, version and all, so the 403 can only be
     * the ability check — a request the validator would have thrown out anyway
     * proves nothing about who is allowed through.
     */
    public function test_partner_ops_staff_cannot_read_or_set_a_slot(): void
    {
        $this->actingAs($this->staff(Role::StaffPartnerOps));

        $this->getJson('/api/admin/media/slots')->assertStatus(403);
        $this->putJson('/api/admin/media/slot/hero', ['version' => 0, 'imgId' => $this->imgId('x')])
            ->assertStatus(403);

        $this->assertNull(SiteContent::value('media'), 'a refused write must leave no row behind');
    }

    /** The owner edits content too, and must not need a second role for it. */
    public function test_the_owner_may_read_the_slots(): void
    {
        $this->actingAs($this->staff(Role::SuperAdmin));

        $this->getJson('/api/admin/media/slots')->assertOk();
    }

    // ------------------------------------------------------------------ read

    public function test_the_schema_covers_every_slot_the_site_stores(): void
    {
        // The row as an import or the live server leaves it.
        SiteContent::query()->create([
            'key' => 'media',
            'value' => array_fill_keys(self::PRODUCTION_KEYS, $this->imgId('seed')),
            'version' => 1,
        ]);
        $this->actingAs($this->staff());

        $keys = array_column($this->getJson('/api/admin/media/slots')->assertOk()->json('slots'), 'key');

        foreach (self::PRODUCTION_KEYS as $key) {
            $this->assertContains($key, $keys, "slot {$key} is stored but no screen could reach it");
        }
        $this->assertSame(count(self::PRODUCTION_KEYS), count($keys), 'no slot the site cannot show');
    }

    /**
     * The client asked the same question of every image in the old panel: which
     * picture on the site is this one? A label alone does not answer it, so the
     * sentence and the size travel with the key.
     */
    public function test_each_slot_says_where_it_appears_and_what_size_fits(): void
    {
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/media/slots')->assertOk();
        $bykey = collect($res->json('slots'))->keyBy('key');

        $this->assertSame('Hero visual', $bykey['hero']['label']);
        $this->assertStringContainsString('home page', $bykey['hero']['where']);
        $this->assertSame('900 × 900 px', $bykey['hero']['size']);

        // The two partner-page slots sit in the same map as the eight home-page
        // ones, so the sentence is the only thing that tells an editor they are
        // editing a different page.
        $this->assertStringContainsString('partner page', $bykey['partnerHero']['where']);
        $this->assertStringContainsString('partner page', $bykey['partnerApp']['where']);

        foreach ($res->json('slots') as $slot) {
            $this->assertNotEmpty($slot['where'], "slot {$slot['key']} says nothing about where it appears");
            $this->assertNotEmpty($slot['size'], "slot {$slot['key']} recommends no size");
        }

        // Laravel's session middleware appends `private`; what matters is that
        // no-store survives, so staff content is never held by a proxy.
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    /**
     * What a visitor sees when a slot is EMPTY differs between these two
     * groups, and an editor acts on the difference.
     *
     * The eight home-page slots have a photograph written into the markup as an
     * inline background-image, with the drawn alternative already
     * display:none — so clearing one restores a photograph, and the legacy
     * panel's "the illustrated placeholder comes back" was simply wrong about
     * them. The two partner-page slots are declared the other way round: their
     * <img> starts with the `hidden` attribute and js/render.js returns before
     * it can unhide it, so that space shows a CSS mock-up until a picture is
     * set, and setting one takes the drawing's place.
     *
     * It is asserted here rather than left to the screen because the screen
     * used to carry its own map of which slots were which — a second copy of a
     * server-declared schema, the same mistake that gave the old photos form a
     * field with no column behind it.
     */
    public function test_each_slot_says_what_shows_when_it_is_empty(): void
    {
        $this->actingAs($this->staff());

        $bykey = collect($this->getJson('/api/admin/media/slots')->assertOk()->json('slots'))->keyBy('key');

        foreach (['hero', 'students', 'partners', 'franchisees', 'universities',
            'collage1', 'collage2', 'collage3'] as $key) {
            $this->assertNull(
                $bykey[$key]['fallback'],
                "{$key} has a photograph built into the page, so its fallback must be null"
            );
        }

        foreach (['partnerHero', 'partnerApp'] as $key) {
            $this->assertNotEmpty(
                $bykey[$key]['fallback'],
                "{$key} shows a drawing when empty and the screen has to be able to say so"
            );
            $this->assertStringContainsString('drawn', $bykey[$key]['fallback']);
        }

        // "Behind" was wrong and would have had an editor sizing a picture to be
        // partly covered: the stylesheet hides the mock-up once the wrapper gets
        // `has-img`, so the picture takes its place.
        $this->assertStringContainsString('replaces', $bykey['partnerHero']['where']);
        $this->assertStringNotContainsString('Behind', $bykey['partnerHero']['where']);
    }

    /**
     * A save has to quote the version it was looking at, so the read has to
     * hand it over. Without this the screen has nothing to quote and every
     * save is a guess.
     */
    public function test_the_slots_carry_the_version_a_save_has_to_quote(): void
    {
        $this->actingAs($this->staff());

        // Before the first save there is no row. Zero, not null — the screen
        // puts this straight into a save body, and the endpoint takes 0 as the
        // version of a map nobody has written yet.
        $this->getJson('/api/admin/media/slots')->assertOk()->assertJsonPath('version', 0);

        SiteContent::query()->create([
            'key' => 'media',
            'value' => ['hero' => $this->imgId('abc')],
            'version' => 7,
        ]);

        $this->getJson('/api/admin/media/slots')->assertOk()->assertJsonPath('version', 7);
    }

    public function test_a_stored_image_id_comes_back_and_an_empty_slot_reads_as_null(): void
    {
        SiteContent::query()->create([
            'key' => 'media',
            // '' is what a cleared slot looks like in an older payload; it means
            // empty, and a screen must not try to render it as an image.
            'value' => ['hero' => $this->imgId('abc'), 'students' => ''],
            'version' => 1,
        ]);
        $this->actingAs($this->staff());

        $bykey = collect($this->getJson('/api/admin/media/slots')->json('slots'))->keyBy('key');

        $this->assertSame($this->imgId('abc'), $bykey['hero']['imgId']);
        $this->assertNull($bykey['students']['imgId']);
        $this->assertNull($bykey['collage1']['imgId'], 'a slot never set has no image');
    }

    // ----------------------------------------------------------------- write

    public function test_setting_a_slot_round_trips_through_the_read_endpoint(): void
    {
        $id = app(ImageService::class)->store(UploadedFile::fake()->image('hero.png', 900, 900));
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/media/slot/hero', ['version' => 0, 'imgId' => $id])
            ->assertOk()
            ->assertJsonPath('media.hero', $id)
            // The version the save answers with is the one the next save must
            // quote, so it has to be the version that was actually stored and
            // not the one that was sent.
            ->assertJsonPath('version', 1);

        $res = $this->getJson('/api/admin/media/slots')->assertOk();
        $this->assertSame(1, $res->json('version'), 'the read and the write must agree on the version');

        $bykey = collect($res->json('slots'))->keyBy('key');
        $this->assertSame($id, $bykey['hero']['imgId']);

        // Clearing it comes back as an empty slot, not as a missing key the
        // screen would have to guess at.
        $this->putJson('/api/admin/media/slot/hero', ['version' => 1, 'imgId' => null])
            ->assertOk()->assertJsonPath('version', 2);

        $bykey = collect($this->getJson('/api/admin/media/slots')->json('slots'))->keyBy('key');
        $this->assertNull($bykey['hero']['imgId']);
    }

    /**
     * The key is a URL segment. Before the allow-list this wrote whatever was
     * asked for, and the invented key then held a reference that kept the
     * upload it named undeletable.
     */
    public function test_an_unknown_slot_key_is_refused_and_stores_nothing(): void
    {
        $this->actingAs($this->staff());

        // A near miss, a real markup key this console does not declare, another
        // site_content singleton, and the right key in the wrong case. Nothing
        // is stored yet, so country_uk_hero is refused here as well — the
        // exception below is for clearing a key that is already there, and this
        // is a write to one that is not.
        foreach (['heroo', 'country_uk_hero', 'settings', 'HERO'] as $key) {
            // A correct version goes with it. The validator answers 422 as
            // well, so a body it would have rejected anyway could not tell us
            // whether the allow-list is still there; the message can.
            $this->putJson("/api/admin/media/slot/{$key}", ['version' => 0, 'imgId' => $this->imgId('x')])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Unknown image slot.');
        }

        $this->assertNull(SiteContent::value('media'), 'not one of those may have been written');
    }

    /**
     * The media map is not limited to these ten keys in the wild. The static
     * pages read about 140 more through data-media — country_uk_hero is one of
     * them, on the UK page — the editor this console replaces could write them,
     * and content:import copies the map in verbatim. Naming an image is enough
     * to make referenceCount() keep its file on disk, so with the allow-list
     * refusing every such key outright there was no request left that could
     * drop the reference: the upload would be pinned there for good.
     *
     * The allow-list still stands in the direction that matters. Clearing can
     * only ever remove; creating is what had to be shut, and stays shut.
     */
    public function test_a_key_the_importer_left_behind_can_be_cleared_but_never_recreated(): void
    {
        $svc = app(ImageService::class);
        $orphan = $svc->store(UploadedFile::fake()->image('uk.png', 400, 300));

        SiteContent::query()->create([
            'key' => 'media',
            'value' => ['hero' => $this->imgId('abc'), 'country_uk_hero' => $orphan],
            'version' => 3,
        ]);
        $this->actingAs($this->staff());

        // Nothing declares country_uk_hero, so it cannot be pointed at a
        // different image — not even now that it exists.
        $this->putJson('/api/admin/media/slot/country_uk_hero', ['version' => 3, 'imgId' => $this->imgId('other')])
            ->assertStatus(422)->assertJsonPath('message', 'Unknown image slot.');

        $this->assertSame($orphan, SiteContent::value('media')['country_uk_hero']);

        // Clearing it is allowed, and takes the last reference with it, which
        // is the whole point: the file goes.
        $this->putJson('/api/admin/media/slot/country_uk_hero', ['version' => 3, 'imgId' => null])
            ->assertOk()->assertJsonMissingPath('media.country_uk_hero');

        $this->assertSame(0, $svc->referenceCount($orphan), 'the reference that pinned the file must be gone');
        Storage::disk('public')->assertMissing('media/'.basename($orphan));

        // The declared slot sharing the row is untouched, and the key is no
        // more creatable now than it was before it was cleared.
        $this->assertSame(['hero' => $this->imgId('abc')], SiteContent::value('media'));

        $this->putJson('/api/admin/media/slot/country_uk_hero', ['version' => 4, 'imgId' => $orphan])
            ->assertStatus(422)->assertJsonPath('message', 'Unknown image slot.');
    }

    // ----------------------------------------------------------- concurrency

    /**
     * The reviewer's scenario, verbatim: two editors open this screen, one sets
     * the hero, the other sets a collage card from the map as it was before.
     * A save rewrites all ten slots, so the second one used to put the hero
     * back to the picture it held when that editor loaded the page — silently,
     * and to a picture the site then served.
     */
    public function test_a_save_quoting_an_old_version_is_refused_and_reverts_nothing(): void
    {
        SiteContent::query()->create([
            'key' => 'media',
            'value' => ['hero' => $this->imgId('old-hero')],
            'version' => 1,
        ]);

        // Both editors read version 1. The first one saves.
        $this->actingAs($this->staff());
        $this->putJson('/api/admin/media/slot/hero', ['version' => 1, 'imgId' => $this->imgId('new-hero')])
            ->assertOk()->assertJsonPath('version', 2);

        // The second still holds version 1, and is setting a different slot —
        // the collision is in the row, not in the slot, which is exactly why
        // it went unnoticed.
        $this->putJson('/api/admin/media/slot/collage1', ['version' => 1, 'imgId' => $this->imgId('card')])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This content was changed by someone else. Reload and reapply your edits.')
            ->assertJsonPath('currentVersion', 2);

        // Refused means nothing was written: not the collage card the second
        // editor asked for, and above all not the hero they would have carried
        // backwards with it.
        $this->assertSame(['hero' => $this->imgId('new-hero')], SiteContent::value('media'));
        $this->assertSame(2, SiteContent::query()->where('key', 'media')->value('version'));
    }

    /**
     * And the refusal has to be recoverable, or it is just a different way to
     * lose the edit. Reloading gives the version the 409 named, and the save
     * then lands beside the other editor's rather than on top of it.
     */
    public function test_the_refused_editor_reloads_and_saves_alongside_the_first(): void
    {
        SiteContent::query()->create([
            'key' => 'media',
            'value' => ['hero' => $this->imgId('new-hero')],
            'version' => 2,
        ]);
        $this->actingAs($this->staff());

        $version = $this->getJson('/api/admin/media/slots')->assertOk()->json('version');

        $this->putJson('/api/admin/media/slot/collage1', ['version' => $version, 'imgId' => $this->imgId('card')])
            ->assertOk()
            ->assertJsonPath('media.hero', $this->imgId('new-hero'))
            ->assertJsonPath('media.collage1', $this->imgId('card'))
            ->assertJsonPath('version', 3);

        $bykey = collect($this->getJson('/api/admin/media/slots')->json('slots'))->keyBy('key');
        $this->assertSame($this->imgId('new-hero'), $bykey['hero']['imgId'], 'both edits survive');
        $this->assertSame($this->imgId('card'), $bykey['collage1']['imgId']);
    }

    /**
     * The version is required, not optional. An omitted one would have to be
     * read as "no opinion" and waved through, which leaves the unguarded write
     * this endpoint is being fixed for sitting there for any future caller to
     * find.
     */
    public function test_a_save_without_a_version_is_refused(): void
    {
        SiteContent::query()->create([
            'key' => 'media',
            'value' => ['hero' => $this->imgId('old-hero')],
            'version' => 1,
        ]);
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/media/slot/hero', ['imgId' => $this->imgId('new-hero')])
            ->assertStatus(422)->assertJsonValidationErrors('version');

        $this->assertSame(['hero' => $this->imgId('old-hero')], SiteContent::value('media'));
    }
}
