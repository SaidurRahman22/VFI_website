<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Blog;
use App\Models\Content\Event;
use App\Models\Content\Photo;
use App\Models\Content\PpDoc;
use App\Models\ContentAuditLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The website-content API the new console edits through.
 *
 * This exists so admin.html can be retired. That page is READ-ONLY — its "New
 * event" buttons only deep-linked into Filament — so this is the first native
 * content CRUD the project has had, and the only thing standing between the
 * client and losing their editors when the old page goes.
 *
 * What is tested harder than the happy path, and why:
 *
 *   THE MODEL STILL OWNS THE CONTRACT. `position`, `legacy_id`, the audit row
 *   and the blog body's HTML stripping belong to ContentItem. A generic
 *   controller that writes ten tables is exactly the kind of code that quietly
 *   routes around all four, so each is asserted through the HTTP layer rather
 *   than trusted because the model has it.
 *
 *   ROLE, not just session. The route group only proves an admin session with
 *   TOTP. Partner-ops staff hold such a session and must still not be able to
 *   rewrite the public website.
 *
 *   THE SLUG IS REQUEST INPUT. An unknown collection must be a flat 404, not a
 *   class-resolution error.
 *
 *   pp_* DATES ARE DISPLAY STRINGS. Their columns are varchar and hold values
 *   like "12 Aug 2026". The page this replaces offered them as date pickers,
 *   which blank anything they cannot parse and then save the blank. A stored
 *   display string has to survive a round trip untouched.
 */
class AdminContentCollectionApiTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::ContentEditor): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => $role->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    // ---------------------------------------------------------------- access

    public function test_content_needs_an_admin_session(): void
    {
        $this->getJson('/api/admin/content/events')->assertStatus(401);
        $this->postJson('/api/admin/content/events', ['title' => 'x'])->assertStatus(401);
    }

    /**
     * Partner-ops staff hold an admin session, so the route group admits them.
     * The per-handler ability check is what must refuse them.
     */
    public function test_partner_ops_staff_cannot_touch_website_content(): void
    {
        $this->actingAs($this->staff(Role::StaffPartnerOps));

        $this->getJson('/api/admin/content/collections')->assertStatus(403);
        $this->getJson('/api/admin/content/events')->assertStatus(403);
        $this->postJson('/api/admin/content/events', ['title' => 'Nope'])->assertStatus(403);
        $this->assertSame(0, Event::query()->count());
    }

    public function test_an_unknown_collection_is_a_flat_404(): void
    {
        $this->actingAs($this->staff());

        // Not a 500: the slug is request input and is resolved through an
        // allow-list, never interpolated into a class name.
        $this->getJson('/api/admin/content/users')->assertStatus(404);
        $this->getJson('/api/admin/content/App%5CModels%5CUser')->assertStatus(404);
    }

    // ------------------------------------------------------------------ read

    public function test_the_tab_strip_lists_all_ten_collections_with_counts(): void
    {
        Event::create(['title' => 'Dhaka fair']);
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/content/collections')->assertOk();

        $this->assertCount(10, $res->json('data'));
        $byslug = collect($res->json('data'))->keyBy('slug');
        $this->assertSame(1, $byslug['events']['count']);
        $this->assertSame(0, $byslug['blogs']['count']);
        $this->assertSame('Photo gallery', $byslug['photos']['label']);
    }

    /**
     * Ten tabs in one strip overflow a 1500px screen, which is how the six
     * partner-console collections ended up behind a scroll arrow. The group
     * also answers the question the client actually asked of every field —
     * where does this text come out on the site?
     */
    public function test_each_collection_says_where_its_content_appears(): void
    {
        $this->actingAs($this->staff());

        $byslug = collect($this->getJson('/api/admin/content/collections')->json('data'))->keyBy('slug');

        $this->assertSame('Public website', $byslug['events']['group']);
        $this->assertSame('Public website', $byslug['photos']['group']);
        $this->assertSame('Partner console', $byslug['pp-managers']['group']);
        $this->assertSame('Partner console', $byslug['pp-notifs']['group']);

        // Two groups, so two strips that each fit.
        $this->assertSame(['Public website', 'Partner console'], $byslug->pluck('group')->unique()->values()->all());

        // A screen entered directly on one collection still knows its group.
        $this->assertSame('Partner console', $this->getJson('/api/admin/content/pp-docs')->json('group'));
    }

    /**
     * The console renders its form from this schema, so the schema travelling
     * with the rows is the feature, not decoration — a missing field is an
     * uneditable column.
     */
    public function test_a_list_carries_the_schema_that_edits_it(): void
    {
        Event::create(['title' => 'Dhaka fair', 'city' => 'Dhaka']);
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/content/events')->assertOk();

        $keys = array_column($res->json('fields'), 'key');
        $this->assertSame(
            ['title', 'date', 'time', 'type', 'city', 'description', 'color', 'img_id'],
            $keys,
            'every editable events column must appear as a field'
        );
        $this->assertSame('title', $res->json('title_key'));
        $this->assertSame('Dhaka', $res->json('data.0.city'));
        // Laravel's session middleware appends `private`; what matters is that
        // no-store survives, so staff content is never held by a proxy.
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_rows_come_back_in_display_order(): void
    {
        // ContentItem puts each new row at the FRONT, so this is newest-first.
        Event::create(['title' => 'First made']);
        Event::create(['title' => 'Second made']);
        $this->actingAs($this->staff());

        $titles = array_column($this->getJson('/api/admin/content/events')->json('data'), 'title');
        $this->assertSame(['Second made', 'First made'], $titles);
    }

    // ----------------------------------------------------------------- write

    public function test_creating_an_item_mints_a_legacy_id_and_puts_it_first(): void
    {
        Event::create(['title' => 'Already there']);
        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/events', [
            'title' => 'UK Spot Admissions Day',
            'date' => '2026-11-04',
            'city' => 'Dhaka',
            'color' => 'b',
        ])->assertCreated();

        $this->assertNotEmpty($res->json('item.legacy_id'), 'the model must mint the public id');
        $this->assertSame('2026-11-04', $res->json('item.date'));

        $titles = array_column($this->getJson('/api/admin/content/events')->json('data'), 'title');
        $this->assertSame(['UK Spot Admissions Day', 'Already there'], $titles);
    }

    /**
     * For blogs, legacy_id IS the public article URL key. A client that could
     * set it could take over an already-published URL, so neither it nor
     * `position` is accepted however the payload is dressed up.
     */
    public function test_a_client_cannot_choose_the_public_id_or_the_position(): void
    {
        $existing = Blog::create(['title' => 'Published last year']);
        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/blogs', [
            'title' => 'Impostor',
            'legacy_id' => $existing->legacy_id,
            'position' => 9999,
        ])->assertCreated();

        $this->assertNotSame($existing->legacy_id, $res->json('item.legacy_id'));
        $this->assertNotSame(9999, $res->json('item.position'));
        $this->assertSame($existing->legacy_id, $existing->fresh()->legacy_id);
    }

    /**
     * Blog bodies are rendered into the article page, so the model strips HTML
     * on save. Writing through a generic controller must not bypass that.
     */
    public function test_a_blog_body_is_still_stripped_of_html(): void
    {
        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/blogs', [
            'title' => 'Scholarships',
            'body' => 'Read this <script>alert(1)</script> carefully.',
        ])->assertCreated();

        $body = (string) Blog::query()->find($res->json('item.id'))?->body;
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringContainsString('Read this', $body);
    }

    public function test_updating_changes_only_the_fields_sent(): void
    {
        $e = Event::create(['title' => 'Dhaka fair', 'city' => 'Dhaka', 'time' => '10:00 am']);
        $this->actingAs($this->staff());

        $this->putJson("/api/admin/content/events/{$e->id}", [
            'title' => 'Dhaka fair (rescheduled)',
            'city' => 'Chattogram',
            'time' => '10:00 am',
        ])->assertOk()->assertJsonPath('item.city', 'Chattogram');

        $this->assertSame('Dhaka fair (rescheduled)', $e->fresh()->title);
        $this->assertSame('10:00 am', $e->fresh()->time);
    }

    public function test_a_required_field_cannot_be_emptied(): void
    {
        $e = Event::create(['title' => 'Dhaka fair']);
        $this->actingAs($this->staff());

        $this->putJson("/api/admin/content/events/{$e->id}", ['title' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('title');
        $this->assertSame('Dhaka fair', $e->fresh()->title);
    }

    public function test_a_card_colour_outside_the_stylesheet_is_refused(): void
    {
        $this->actingAs($this->staff());

        // Only a/b/c have gradients in the CSS; anything else renders as an
        // unstyled grey box on the live site.
        $this->postJson('/api/admin/content/events', ['title' => 'x', 'color' => 'rebeccapurple'])
            ->assertStatus(422)->assertJsonValidationErrors('color');
    }

    public function test_deleting_is_recoverable_and_leaves_the_list(): void
    {
        $e = Event::create(['title' => 'Cancelled fair']);
        $this->actingAs($this->staff());

        $this->deleteJson("/api/admin/content/events/{$e->id}")->assertOk();

        $this->assertCount(0, $this->getJson('/api/admin/content/events')->json('data'));
        $this->assertNotNull(Event::withTrashed()->find($e->id)?->deleted_at, 'a mistake must be recoverable');
        $this->deleteJson("/api/admin/content/events/{$e->id}")->assertStatus(404);
    }

    // --------------------------------------------------------------- reorder

    public function test_reordering_swaps_with_the_neighbour_and_stops_at_the_ends(): void
    {
        Event::create(['title' => 'Bottom']);
        Event::create(['title' => 'Middle']);
        Event::create(['title' => 'Top']);
        $this->actingAs($this->staff());

        $rows = $this->getJson('/api/admin/content/events')->json('data');
        $this->assertSame(['Top', 'Middle', 'Bottom'], array_column($rows, 'title'));

        $middleId = $rows[1]['id'];
        $this->putJson("/api/admin/content/events/{$middleId}/move", ['direction' => 'up'])->assertOk();

        $titles = array_column($this->getJson('/api/admin/content/events')->json('data'), 'title');
        $this->assertSame(['Middle', 'Top', 'Bottom'], $titles);

        // Now first. Moving up again has nowhere to go, and must say so rather
        // than silently doing nothing or renumbering the list.
        $this->putJson("/api/admin/content/events/{$middleId}/move", ['direction' => 'up'])
            ->assertStatus(422)->assertJsonPath('message', 'This is already first in the list.');

        $this->putJson("/api/admin/content/events/{$middleId}/move", ['direction' => 'sideways'])
            ->assertStatus(422)->assertJsonValidationErrors('direction');
    }

    // ------------------------------------------------- the awkward per-table

    /**
     * pp_docs.date is a varchar holding whatever reads well on the card. The
     * page this replaces offered it as a date picker, which shows an empty box
     * for "12 Aug 2026" and then saves that emptiness over it.
     */
    public function test_a_display_date_survives_a_round_trip_verbatim(): void
    {
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/content/pp-docs')->assertOk();
        $dateField = collect($res->json('fields'))->firstWhere('key', 'date');
        $this->assertSame('text', $dateField['type'], 'a picker would destroy these values');

        $created = $this->postJson('/api/admin/content/pp-docs', [
            'title' => 'Student Enquiry Form',
            'date' => '12 Aug 2026',
            'size' => '0.16 MB',
        ])->assertCreated();

        $this->assertSame('12 Aug 2026', $created->json('item.date'));
        $this->assertSame('12 Aug 2026', PpDoc::query()->find($created->json('item.id'))?->date);
    }

    /**
     * The old form offered photos a `title` field with no column behind it, so
     * every caption typed there was discarded on save. The column that does
     * exist, and that a screen reader reads, is `alt`.
     */
    public function test_photos_edit_alt_text_rather_than_a_field_with_no_column(): void
    {
        $this->actingAs($this->staff());

        $keys = array_column($this->getJson('/api/admin/content/photos')->json('fields'), 'key');
        $this->assertSame(['img_id', 'caption', 'alt'], $keys);

        $res = $this->postJson('/api/admin/content/photos', [
            'img_id' => '/storage/media/abc.jpg',
            'caption' => 'Graduation day',
            'alt' => 'Students in gowns throwing their caps',
        ])->assertCreated();

        $this->assertSame(
            'Students in gowns throwing their caps',
            Photo::query()->find($res->json('item.id'))?->alt
        );
    }

    /** Every one of the ten is reachable and editable, not just the four with images. */
    public function test_all_ten_collections_accept_a_write(): void
    {
        $this->actingAs($this->staff());

        $required = [
            'events' => ['title' => 'A fair'],
            'blogs' => ['title' => 'A post'],
            'news' => ['title' => 'A headline'],
            'photos' => ['caption' => 'A photo'],
            'pp-managers' => ['name' => 'Tahmeed Rahman'],
            'pp-updates' => ['title' => 'An update'],
            'pp-quicklinks' => ['label' => 'A link'],
            'pp-docs' => ['title' => 'A document'],
            'pp-emails' => ['subject' => 'A subject'],
            'pp-notifs' => ['title' => 'A notification'],
        ];

        foreach ($required as $slug => $payload) {
            $this->getJson("/api/admin/content/{$slug}")->assertOk();
            $id = $this->postJson("/api/admin/content/{$slug}", $payload)->assertCreated()->json('item.id');
            $this->putJson("/api/admin/content/{$slug}/{$id}", $payload)->assertOk();
            $this->deleteJson("/api/admin/content/{$slug}/{$id}")->assertOk();
        }
    }

    // ----------------------------------------------------------------- audit

    /** Someone rewrote the public site. That has to be answerable afterwards. */
    public function test_every_write_leaves_an_audit_row(): void
    {
        $this->actingAs($this->staff());

        $id = $this->postJson('/api/admin/content/events', ['title' => 'Audited fair'])
            ->assertCreated()->json('item.id');
        $this->putJson("/api/admin/content/events/{$id}", ['title' => 'Audited fair v2'])->assertOk();
        $this->deleteJson("/api/admin/content/events/{$id}")->assertOk();

        $actions = ContentAuditLog::query()->where('entity', 'events')->pluck('action')->all();
        $this->assertContains('create', $actions);
        $this->assertContains('update', $actions);
        $this->assertContains('delete', $actions);
    }
}
