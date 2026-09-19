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
 *   class-resolution error. That goes for the undo routes too, which take an id
 *   as well and so are the newest place a slug could have become a class name.
 *
 *   A PROMISE THE SCREEN MAKES. The delete confirmation tells the person the
 *   item can be restored. Nothing in the API could do that until trashed() and
 *   restore() existed, so the sentence was true of the database and false of
 *   everyone reading it. Those two handlers are therefore load-bearing, not
 *   convenient, and the awkward cases carry the weight: a restore with nothing
 *   to restore has to refuse rather than answer 200 over no work at all.
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
        $this->getJson('/api/admin/content/events/trashed')->assertStatus(401);
        $this->postJson('/api/admin/content/events/1/restore')->assertStatus(401);
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
     * partner-console collections ended up behind a scroll arrow. Each group is
     * now its own sidebar entry and its own route, so the group a collection
     * belongs to decides where it is reachable — and it answers the question the
     * client asked of every field: where does this text come out on the site?
     */
    public function test_each_collection_says_where_its_content_appears(): void
    {
        $this->actingAs($this->staff());

        $byslug = collect($this->getJson('/api/admin/content/collections')->json('data'))->keyBy('slug');

        $this->assertSame('public-website', $byslug['events']['group']);
        $this->assertSame('public-website', $byslug['photos']['group']);
        $this->assertSame('partner-console', $byslug['pp-managers']['group']);
        $this->assertSame('partner-console', $byslug['pp-notifs']['group']);

        // The console routes on the slug (/content/public) and prints the label,
        // so both travel together - a client that had to derive one from the
        // other would be the second place these names are written down.
        $this->assertSame('Public website', $byslug['events']['group_label']);
        $this->assertSame('Partner console', $byslug['pp-managers']['group_label']);

        // Exactly two groups, each small enough for one tab strip.
        $this->assertSame(
            ['public-website', 'partner-console'],
            $byslug->pluck('group')->unique()->values()->all()
        );
        $this->assertLessThanOrEqual(6, $byslug->groupBy('group')->map->count()->max());

        // A screen entered directly on one collection still knows its group.
        $res = $this->getJson('/api/admin/content/pp-docs');
        $this->assertSame('partner-console', $res->json('group'));
        $this->assertSame('Partner console', $res->json('group_label'));
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

    // -------------------------------------------------- removing, and undoing

    /**
     * The confirmation dialog promises a removal can be undone, so the removed
     * rows have to be reachable, and reachable with enough on them to recognise
     * which one you want: the same title and the same subtitle the live list
     * shows, because the screen draws both from one schema.
     */
    public function test_a_removed_item_leaves_the_list_and_turns_up_in_the_removed_one(): void
    {
        Event::create(['title' => 'Dhaka fair']);
        $gone = Event::create(['title' => 'Cancelled fair', 'city' => 'Sylhet']);
        $this->actingAs($this->staff());

        $this->assertSame([], $this->getJson('/api/admin/content/events/trashed')->assertOk()->json('data'));

        $this->deleteJson("/api/admin/content/events/{$gone->id}")->assertOk();

        $live = array_column($this->getJson('/api/admin/content/events')->json('data'), 'title');
        $this->assertSame(['Dhaka fair'], $live, 'a removed item must be off the list it was on');

        $removed = $this->getJson('/api/admin/content/events/trashed')->assertOk();
        $this->assertSame(['Cancelled fair'], array_column($removed->json('data'), 'title'));
        $this->assertSame('Sylhet', $removed->json('data.0.city'));
        // The dialog says when it went, so the row has to carry when.
        $this->assertNotNull($removed->json('data.0.removed_at'));
        $this->assertStringContainsString('no-store', (string) $removed->headers->get('Cache-Control'));
    }

    /**
     * Back where it was, not at the front. `position` survives the round trip
     * untouched, so an editor who removes the wrong row and puts it back has
     * not also silently reordered the page it appears on.
     */
    public function test_putting_one_back_returns_it_to_the_list_where_it_was(): void
    {
        Event::create(['title' => 'Last']);
        $middle = Event::create(['title' => 'Middle']);
        Event::create(['title' => 'First']);
        $this->actingAs($this->staff());

        $this->deleteJson("/api/admin/content/events/{$middle->id}")->assertOk();
        $this->assertSame(
            ['First', 'Last'],
            array_column($this->getJson('/api/admin/content/events')->json('data'), 'title')
        );

        $res = $this->postJson("/api/admin/content/events/{$middle->id}/restore")->assertOk();
        $this->assertSame('Middle', $res->json('item.title'));

        $this->assertSame(
            ['First', 'Middle', 'Last'],
            array_column($this->getJson('/api/admin/content/events')->json('data'), 'title')
        );
        $this->assertSame([], $this->getJson('/api/admin/content/events/trashed')->json('data'));
    }

    /**
     * Undoing a removal writes to the public website, so it is gated exactly as
     * the removal was. Partner-ops staff hold an admin session and the route
     * group admits them; the handler is what must refuse.
     */
    public function test_only_content_staff_can_see_or_undo_a_removal(): void
    {
        $e = Event::create(['title' => 'Dhaka fair']);
        $e->delete();

        $this->actingAs($this->staff(Role::StaffPartnerOps));

        $this->getJson('/api/admin/content/events/trashed')->assertStatus(403);
        $this->postJson("/api/admin/content/events/{$e->id}/restore")->assertStatus(403);
        $this->assertNotNull(
            Event::withTrashed()->find($e->id)?->deleted_at,
            'a refused restore must not have restored it anyway'
        );
    }

    /** The undo routes resolve their slug through the same allow-list. */
    public function test_the_undo_routes_are_a_flat_404_for_an_unknown_collection(): void
    {
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/content/users/trashed')->assertStatus(404);
        $this->postJson('/api/admin/content/users/1/restore')->assertStatus(404);
        $this->postJson('/api/admin/content/App%5CModels%5CUser/1/restore')->assertStatus(404);
    }

    /**
     * A restore that had nothing to restore must say so. Answering 200 would
     * tell someone their content is back — the exact class of lie this screen
     * keeps being rebuilt to stop telling.
     */
    public function test_restoring_something_that_was_never_removed_is_refused(): void
    {
        $e = Event::create(['title' => 'Still on the site']);
        $this->actingAs($this->staff());

        $this->postJson("/api/admin/content/events/{$e->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'That one has not been removed — it is still on the website.');

        // An id nobody ever created is the 404 it is on every other handler,
        // and not the sentence above, which would be false of it.
        $this->postJson('/api/admin/content/events/9999/restore')->assertStatus(404);

        // Two people with the dialog open, both clicking: the second one is
        // told nothing happened rather than told it worked twice.
        $this->deleteJson("/api/admin/content/events/{$e->id}")->assertOk();
        $this->postJson("/api/admin/content/events/{$e->id}/restore")->assertOk();
        $this->postJson("/api/admin/content/events/{$e->id}/restore")->assertStatus(422);
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

    /**
     * One step per request was the whole reorder vocabulary, which put the
     * front of a thirty-item gallery twenty-nine clicks away from a photo
     * uploaded to the end of it.
     *
     * The second assertion is the one that matters as much as the order:
     * everything that did not move must still hold the position it held.
     * Renumbering the list to make one row first is how two editors tidying
     * different ends of a long gallery overwrite each other.
     */
    public function test_move_to_top_and_to_bottom_get_there_in_one_request(): void
    {
        // ContentItem puts each new row at the front, so this creates bottom-up.
        foreach (['Fifth', 'Fourth', 'Third', 'Second', 'First'] as $title) {
            Event::create(['title' => $title]);
        }
        $this->actingAs($this->staff());

        $rows = $this->getJson('/api/admin/content/events')->json('data');
        $this->assertSame(['First', 'Second', 'Third', 'Fourth', 'Fifth'], array_column($rows, 'title'));

        $before = collect($rows)->pluck('position', 'title');
        $lastId = end($rows)['id'];

        $this->putJson("/api/admin/content/events/{$lastId}/move", ['direction' => 'top'])->assertOk();

        $moved = $this->getJson('/api/admin/content/events')->json('data');
        $this->assertSame(['Fifth', 'First', 'Second', 'Third', 'Fourth'], array_column($moved, 'title'));
        $this->assertSame(
            $before->except('Fifth')->all(),
            collect($moved)->pluck('position', 'title')->except('Fifth')->all(),
            'moving one row to the top must not have renumbered the others'
        );

        $this->putJson("/api/admin/content/events/{$lastId}/move", ['direction' => 'bottom'])->assertOk();
        $this->assertSame(
            ['First', 'Second', 'Third', 'Fourth', 'Fifth'],
            array_column($this->getJson('/api/admin/content/events')->json('data'), 'title')
        );

        // Already at that end. Refused, and in the same words the one-step move
        // uses, rather than written again — an accepted no-op would walk the
        // position further out on every extra click.
        $this->putJson("/api/admin/content/events/{$lastId}/move", ['direction' => 'bottom'])
            ->assertStatus(422)->assertJsonPath('message', 'This is already last in the list.');

        $this->putJson("/api/admin/content/events/{$rows[0]['id']}/move", ['direction' => 'top'])
            ->assertStatus(422)->assertJsonPath('message', 'This is already first in the list.');
    }

    /**
     * Two people each send a different row to the top inside one read window,
     * so both land on the same position — which moveToEnd's own docblock
     * accepts, because the list then separates them by id.
     *
     * The bug that hides here: a neighbour lookup that compares position alone
     * finds nothing above the row that is visibly second, so "one step up"
     * refuses on a row the person can see is not first. A button that does
     * nothing is the single most common complaint on this project.
     */
    public function test_one_step_up_still_works_when_two_rows_share_a_position(): void
    {
        $a = Event::create(['title' => 'Tied first']);
        $b = Event::create(['title' => 'Tied second']);

        // The state two concurrent "to the top" presses leave behind.
        $a->forceFill(['position' => -5])->save();
        $b->forceFill(['position' => -5])->save();

        $this->actingAs($this->staff());

        // ordered() is position ASC, id ASC, so the lower id renders first.
        $titles = array_column($this->getJson('/api/admin/content/events')->json('data'), 'title');
        $this->assertSame(['Tied first', 'Tied second'], $titles);

        $this->putJson("/api/admin/content/events/{$b->id}/move", ['direction' => 'up'])
            ->assertOk();

        $this->assertSame(
            ['Tied second', 'Tied first'],
            array_column($this->getJson('/api/admin/content/events')->json('data'), 'title'),
            'the row that was visibly second must actually move'
        );
    }

    // ----------------------------------------------------------------- sort

    /**
     * Sorting a list is not reordering the site, and the two must never be
     * confused: `position` is what the public page renders in, and a column
     * header that quietly rewrote it would change the live site by being
     * clicked on. So the default is still display order, a sort is a view of
     * the same rows, and nothing is written.
     */
    public function test_a_list_can_be_sorted_without_touching_the_running_order(): void
    {
        Event::create(['title' => 'Beta']);
        Event::create(['title' => 'Alpha']);
        Event::create(['title' => 'Gamma']);
        $this->actingAs($this->staff());

        $before = collect($this->getJson('/api/admin/content/events')->json('data'))
            ->pluck('position', 'title');
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], $before->keys()->all(), 'newest first, as always');

        $asc = $this->getJson('/api/admin/content/events?sort=title&direction=asc')->assertOk();
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($asc->json('data'), 'title'));
        $this->assertSame('title', $asc->json('sort'));
        $this->assertSame('asc', $asc->json('direction'));

        $desc = $this->getJson('/api/admin/content/events?sort=title&direction=desc')->assertOk();
        $this->assertSame(['Gamma', 'Beta', 'Alpha'], array_column($desc->json('data'), 'title'));

        // A direction left off is ascending, not a refusal.
        $this->assertSame(
            ['Alpha', 'Beta', 'Gamma'],
            array_column($this->getJson('/api/admin/content/events?sort=title')->json('data'), 'title')
        );

        // And the site is where it was.
        $this->assertSame(
            $before->all(),
            collect($this->getJson('/api/admin/content/events')->json('data'))->pluck('position', 'title')->all(),
            'sorting a screen must never renumber the rows underneath it'
        );
    }

    /**
     * The column name is request input, exactly like the collection slug, and
     * is allow-listed for the same reason. Refused rather than ignored: a list
     * quietly coming back in display order under a highlighted column header
     * is indistinguishable, on screen, from a sort that worked.
     */
    public function test_a_sort_column_that_is_not_a_column_is_refused(): void
    {
        Event::create(['title' => 'Dhaka fair']);
        $this->actingAs($this->staff());

        foreach (['deleted_at', 'password', 'title; DROP TABLE events', '(SELECT 1)'] as $sort) {
            $this->getJson('/api/admin/content/events?sort='.urlencode($sort))
                ->assertStatus(422)->assertJsonValidationErrors('sort');
        }

        $this->getJson('/api/admin/content/events?sort=title&direction=sideways')
            ->assertStatus(422)->assertJsonValidationErrors('direction');

        // A direction with nothing to sort by would come back in plain display
        // order, which on screen looks exactly like a sort that failed.
        $this->getJson('/api/admin/content/events?direction=desc')
            ->assertStatus(422)->assertJsonValidationErrors('sort');

        // The table is still there and still readable, which is the point of
        // the injection cases above.
        $this->assertSame(
            ['Dhaka fair'],
            array_column($this->getJson('/api/admin/content/events')->assertOk()->json('data'), 'title')
        );
    }

    /**
     * A column belongs to one collection only. events has `city`, blogs does
     * not, and offering blogs a sort by it would 500 on a column that is not
     * there.
     */
    public function test_a_column_from_another_collection_is_not_sortable_here(): void
    {
        $this->actingAs($this->staff());

        $this->assertContains('city', $this->getJson('/api/admin/content/events')->json('sortable'));
        $this->assertNotContains('city', $this->getJson('/api/admin/content/blogs')->json('sortable'));

        $this->getJson('/api/admin/content/blogs?sort=city')
            ->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    /**
     * "What did I change last" is the question an editor opens this screen
     * with. The column existed all along; the row simply never carried it, so
     * the console could not offer the one sort every list of edited things is
     * expected to have.
     */
    public function test_every_row_says_when_it_was_last_changed(): void
    {
        $old = Event::create(['title' => 'Edited last year']);
        $recent = Event::create(['title' => 'Edited this morning']);
        Event::query()->whereKey($old->id)->update(['updated_at' => now()->subYear()]);
        Event::query()->whereKey($recent->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/content/events?sort=updated_at&direction=desc')->assertOk();
        $this->assertSame(
            ['Edited this morning', 'Edited last year'],
            array_column($res->json('data'), 'title')
        );

        // ISO-8601, because only the browser knows the reader's timezone.
        $this->assertNotNull($res->json('data.0.updated_at'));
        $this->assertSame(
            now()->subMinutes(5)->format(\DateTimeInterface::ATOM),
            $res->json('data.0.updated_at')
        );

        // The removed list is drawn from the same row shape.
        $this->deleteJson("/api/admin/content/events/{$recent->id}")->assertOk();
        $this->assertNotNull($this->getJson('/api/admin/content/events/trashed')->json('data.0.updated_at'));
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
            'img_id' => 'assets/img/campus.jpg',
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

            // The undo half is not events-only either.
            $this->assertNotEmpty($this->getJson("/api/admin/content/{$slug}/trashed")->assertOk()->json('data'));
            $this->postJson("/api/admin/content/{$slug}/{$id}/restore")->assertOk();
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

    /**
     * Putting a page back on the public site is a change to the public site,
     * and the one write the model's audit trait cannot see: it hooks
     * created/updated/deleted, and SoftDeletes fires `restored`. The controller
     * writes this row itself, under the entity and id the trait uses, so a
     * removal and its undo are two rows of one story rather than two queries.
     */
    public function test_putting_one_back_is_recorded_in_the_audit_log_too(): void
    {
        $this->actingAs($this->staff());

        $id = $this->postJson('/api/admin/content/events', ['title' => 'Audited fair'])
            ->assertCreated()->json('item.id');
        $this->deleteJson("/api/admin/content/events/{$id}")->assertOk();
        $this->postJson("/api/admin/content/events/{$id}/restore")->assertOk();

        $actions = ContentAuditLog::query()
            ->where('entity', 'events')
            ->where('entity_id', Event::query()->find($id)?->legacy_id)
            ->pluck('action')->all();

        $this->assertContains('delete', $actions);
        $this->assertContains('restore', $actions);
    }
}
