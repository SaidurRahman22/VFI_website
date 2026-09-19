<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Photographs on the country pages: the fields that declare them, and the
 * allow-list that decides which ids may be published.
 *
 * Why the allow-list is the feature rather than a detail of it. The stored id
 * is deliberately visible and editable on the screen - it is how a row is
 * pointed at a photo already bundled with the site - so it can be typed as well
 * as uploaded, and js/render.js puts whatever is stored inside a CSS url() on
 * six public pages. One editor write of https://evil.example/beacon.png would
 * then be fetched by every anonymous visitor to those pages, handing a third
 * party their IP address and the page they were reading, and the public bundle
 * hands that out for a minute at a time from cache. Nothing else in the request
 * path has an opinion about the value: the route validates
 * 'value' => ['present'] and the column is a JSONB cast.
 *
 * What else is pinned here:
 *
 *   ONLY TWO LISTS GET A FIELD. A picture with nowhere to go is the defect this
 *   project has already fixed twice - fourteen country blocks whose markup
 *   advertised a hook that no renderer listened to. Only .unic__logo and
 *   .admit__ava have a tile to paint, so a third image field would be an hour
 *   of an editor's work that no visitor ever sees.
 *
 *   UNDESCRIBED KEYS SURVIVE. The console posts back the whole object it
 *   loaded, and the services blocks carry keys this schema has never described
 *   (desc, ctaLabel, ctaHref). A save that dropped what it did not recognise
 *   would delete live page copy on the first save an editor made.
 */
class AdminGroupedImageFieldTest extends TestCase
{
    use RefreshDatabase;

    /** What ImageService::store() hands back: /storage/media/<sha256>.jpg. */
    private const UPLOADED = '/storage/media/3f9c2b1a3f9c2b1a3f9c2b1a3f9c2b1a3f9c2b1a3f9c2b1a3f9c2b1a3f9c2b1a.jpg';

    private function staff(Role $role = Role::ContentEditor): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => $role->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    /** One country's bucket, as the console posts it back. */
    private function uk(array $overrides = []): array
    {
        return ['uk' => array_merge([
            'heroTitle' => 'Study in the UK',
            'universities' => [
                ['name' => 'University of Bristol', 'loc' => 'Bristol, England',
                    'note1' => 'Russell Group', 'note2' => '', 'img' => ''],
            ],
            'admits' => [
                ['name' => 'Nusrat Islam', 'uni' => 'University of Bristol',
                    'prog' => 'MSc Business Analytics', 'initials' => '', 'img' => ''],
            ],
        ], $overrides)];
    }

    // ---------------------------------------------------------------- schema

    public function test_only_the_university_and_admit_rows_offer_an_image_field(): void
    {
        $this->actingAs($this->staff());

        $grouped = $this->getJson('/api/admin/content/singleton/countries')->assertOk()->json('grouped');

        $declared = [];
        foreach ($grouped['lists'] as $list) {
            foreach ($list['item'] ?? [] as $field) {
                if ($field['type'] === 'image') {
                    $declared[$list['key']][] = $field['key'];
                }
            }
        }

        $this->assertSame(['universities' => ['img'], 'admits' => ['img']], $declared);

        // A hero photograph is fixed in number and page-level, so it belongs to
        // the server-declared media slots, not to a per-row field. An image
        // here would be a third way to attach a picture to one page.
        $this->assertSame([], array_filter($grouped['fields'], fn ($f) => $f['type'] === 'image'));
    }

    public function test_each_image_field_says_on_screen_what_happens_when_it_is_left_empty(): void
    {
        $this->actingAs($this->staff());

        $lists = collect($this->getJson('/api/admin/content/singleton/countries')->assertOk()->json('grouped.lists'))
            ->keyBy('key');

        $logo = collect($lists['universities']['item'])->firstWhere('key', 'img');
        $photo = collect($lists['admits']['item'])->firstWhere('key', 'img');

        $this->assertSame('Logo', $logo['label']);
        $this->assertSame('Photo', $photo['label']);

        // The fallback is the whole reason an empty field is safe here, and an
        // editor who cannot see that will fill six rows to avoid a blank tile
        // that was never going to be blank.
        $this->assertStringContainsString('Left empty', $logo['hint']);
        $this->assertStringContainsString('Left empty', $photo['hint']);
    }

    // ------------------------------------------------------------ round trip

    public function test_an_uploaded_id_round_trips_and_reaches_the_public_bundle(): void
    {
        $this->actingAs($this->staff());

        $value = $this->uk();
        $value['uk']['universities'][0]['img'] = self::UPLOADED;
        $value['uk']['admits'][0]['img'] = self::UPLOADED;

        $this->putJson('/api/admin/content/singleton/countries', ['version' => 0, 'value' => $value])
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('value.uk.universities.0.img', self::UPLOADED);

        $this->getJson('/api/admin/content/singleton/countries')->assertOk()
            ->assertJsonPath('value.uk.admits.0.img', self::UPLOADED);

        // The field is only worth anything if it reaches the page, and the page
        // reads this one file.
        $this->assertStringContainsString(
            self::UPLOADED,
            $this->getJson('/api/content/bundle')->assertOk()->getContent(),
        );
    }

    public function test_a_photo_bundled_with_the_site_is_accepted_and_trimmed(): void
    {
        $this->actingAs($this->staff());

        $value = $this->uk();
        // Pasted by hand, which is what this shape is for - reusing a photo the
        // repo already ships instead of uploading a second copy of it. A
        // trailing space is a refusal an editor cannot see the reason for.
        $value['uk']['universities'][0]['img'] = '  assets/img/campus.jpg  ';

        $this->putJson('/api/admin/content/singleton/countries', ['version' => 0, 'value' => $value])->assertOk();

        $this->assertSame(
            'assets/img/campus.jpg',
            SiteContent::value('countries')['uk']['universities'][0]['img'],
        );
    }

    public function test_an_empty_image_clears_the_row_and_keeps_the_built_in_card(): void
    {
        SiteContent::query()->create(['key' => 'countries', 'version' => 1,
            'value' => $this->uk(['universities' => [['name' => 'Bristol', 'img' => self::UPLOADED]]])]);
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/countries', [
            'version' => 1,
            'value' => $this->uk(['universities' => [['name' => 'Bristol', 'img' => '']]]),
        ])->assertOk();

        $this->assertSame('', SiteContent::value('countries')['uk']['universities'][0]['img']);
    }

    // ------------------------------------------------------------ allow-list

    public function test_an_id_the_website_cannot_load_is_refused_and_changes_nothing(): void
    {
        $unusable = [
            'a remote beacon' => 'https://evil.example/beacon.png',
            'a protocol-relative host' => '//evil.example/beacon.png',
            'not a content hash' => '/storage/media/notahash.jpg',
            'a traversal out of the bundled folder' => 'assets/img/../../.env',
            // Never re-encoded by the pipeline, and a script host in a browser.
            'an svg' => 'assets/img/logo.svg',
            // The retired admin.html wrote these; they resolve in one editor's
            // own IndexedDB and nowhere else, least of all on a visitor's page.
            'a legacy browser-store key' => 'img_1699999999',
            'an inline data url' => 'data:image/png;base64,iVBORw0KGgo=',
            // Deliberately strict: a cache-buster on a bundled path, and a name
            // that is not lower-case, are both refused here although the
            // browser copy of this list tolerates them. That copy only decides
            // what to draw; what may be stored is decided once, on the server.
            'a cache-buster query' => 'assets/img/campus.jpg?v=2',
            'an upper-case name' => 'assets/img/Campus.JPG',
        ];

        SiteContent::query()->create(['key' => 'countries', 'version' => 1, 'value' => $this->uk()]);
        $this->actingAs($this->staff());

        foreach ($unusable as $what => $id) {
            $value = $this->uk();
            $value['uk']['universities'][0]['img'] = $id;

            $res = $this->putJson('/api/admin/content/singleton/countries', ['version' => 1, 'value' => $value]);
            $this->assertSame(422, $res->getStatusCode(), "{$what} must not be publishable");

            // Refused, not blanked and saved: the rest of the form is untouched
            // and the version has not moved, so the editor's other edits are
            // still theirs to correct and send again.
            $row = SiteContent::query()->where('key', 'countries')->first();
            $this->assertSame(1, (int) $row->version, "{$what} must not bump the version");
            $this->assertSame('', $row->value['uk']['universities'][0]['img']);

            // The reason any of this matters: the value is public the moment it
            // is stored.
            $this->assertStringNotContainsString($id, $this->getJson('/api/content/bundle')->getContent());
        }
    }

    public function test_the_refusal_names_the_row_it_came_from(): void
    {
        $this->actingAs($this->staff());

        $value = $this->uk();
        $value['uk']['admits'][0]['img'] = 'https://evil.example/beacon.png';

        $message = $this->putJson('/api/admin/content/singleton/countries', ['version' => 0, 'value' => $value])
            ->assertStatus(422)->json('message');

        // A form with forty fields on it and "invalid input" at the top is a
        // message nobody can act on.
        $this->assertStringContainsString('UK', $message);
        $this->assertStringContainsString('student 1', $message);
        $this->assertStringContainsString('Photo', $message);
    }

    /**
     * The region bands hold image slot names in plain `text` fields. They are
     * the wrong shape and they are on the list to fix, but fixing them by
     * accident here - refusing what an editor has already saved - would take
     * the Asia and Europe pages down with it.
     */
    public function test_a_region_band_image_slot_is_not_retroactively_refused(): void
    {
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/regions', ['version' => 0, 'value' => [
            'asia' => ['bands' => [['name' => 'Japan', 'img1' => 'asia_japan_1', 'img2' => '', 'img3' => '']]],
        ]])->assertOk();

        $this->assertSame('asia_japan_1', SiteContent::value('regions')['asia']['bands'][0]['img1']);
    }

    /**
     * ...but a slot box is not a free-text box.
     *
     * VFI.getImage() resolves anything carrying a scheme, a slash or an image
     * extension to itself, so before the `slot` type existed an editor could
     * put a third party's URL in here and every visitor to the Asia page would
     * fetch it. A slot name is an identifier; nothing that can express a
     * request belongs in it.
     */
    public function test_a_region_band_image_slot_refuses_anything_that_is_not_a_slot_name(): void
    {
        $this->actingAs($this->staff());

        foreach ([
            'https://evil.example/beacon.png',
            '//evil.example/beacon.png',
            '/storage/media/../../etc/passwd',
            'asia/japan.jpg',
            'javascript:alert(1)',
            'a"onerror="x',
        ] as $bad) {
            $this->putJson('/api/admin/content/singleton/regions', ['version' => 0, 'value' => [
                'asia' => ['bands' => [['name' => 'Japan', 'img1' => $bad]]],
            ]])->assertStatus(422);
        }

        // and nothing was written by any of them
        $this->assertNull(SiteContent::value('regions'));
    }

    // ---------------------------------------------------------------- bounds

    public function test_a_key_the_schema_does_not_describe_still_survives_a_country_save(): void
    {
        $this->actingAs($this->staff());

        $value = $this->uk(['someFutureBlock' => 'keep me']);
        $value['uk']['universities'][0]['futureField'] = 'keep me too';

        $this->putJson('/api/admin/content/singleton/countries', ['version' => 0, 'value' => $value])->assertOk();

        $stored = SiteContent::value('countries')['uk'];
        $this->assertSame('keep me', $stored['someFutureBlock']);
        $this->assertSame('keep me too', $stored['universities'][0]['futureField']);
    }

    public function test_a_list_longer_than_any_page_renders_is_refused(): void
    {
        $this->actingAs($this->staff());

        $rows = array_fill(0, 200, ['name' => 'University', 'loc' => '', 'note1' => '', 'note2' => '', 'img' => '']);

        $this->putJson('/api/admin/content/singleton/countries', [
            'version' => 0, 'value' => $this->uk(['universities' => $rows]),
        ])->assertStatus(422);

        $this->assertNull(SiteContent::query()->where('key', 'countries')->first());
    }

    public function test_a_short_field_holding_a_document_is_refused_but_a_paragraph_field_is_not(): void
    {
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/countries', [
            'version' => 0,
            'value' => $this->uk(['universities' => [['name' => str_repeat('a', 501)]]]),
        ])->assertStatus(422);

        // A textarea is a different limit, not the same one: the overview intro
        // is meant to hold prose.
        $this->putJson('/api/admin/content/singleton/countries', [
            'version' => 0,
            'value' => $this->uk(['overviewLead' => str_repeat('a', 5000)]),
        ])->assertOk();
    }

    /**
     * Every byte written here is downloaded by every visitor to the site with
     * the page they asked for, and re-hashed per request to build the bundle's
     * ETag. Nothing above catches this on its own: each row and each string is
     * within its limit.
     */
    public function test_a_save_too_large_to_publish_is_refused(): void
    {
        $this->actingAs($this->staff());

        $rows = array_fill(0, 30, ['title' => 'Scholarship', 'desc' => str_repeat('a', 39000)]);

        $this->putJson('/api/admin/content/singleton/countries', [
            'version' => 0, 'value' => $this->uk(['scholarships' => $rows]),
        ])->assertStatus(422);

        $this->assertNull(SiteContent::query()->where('key', 'countries')->first());
    }

    public function test_content_nested_deeper_than_any_page_reads_is_refused(): void
    {
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/countries', [
            'version' => 0,
            'value' => ['uk' => ['universities' => [['name' => ['deeper' => ['deeper' => ['deeper' => 'x']]]]]]],
        ])->assertStatus(422);
    }

    public function test_a_flat_singleton_is_left_alone_by_the_grouped_checks(): void
    {
        SiteContent::query()->create(['key' => 'settings', 'version' => 1, 'value' => ['brand' => 'VFI']]);
        $this->actingAs($this->staff());

        // `settings` is a flat key with its own schema and no image fields; the
        // walk above must not start applying country rules to it.
        $this->putJson('/api/admin/content/singleton/settings', [
            'version' => 1, 'value' => ['brand' => 'VFI', 'about' => str_repeat('a', 3000)],
        ])->assertOk();
    }
}
