<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Event;
use App\Models\Content\Photo;
use App\Models\ContentAuditLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing or restoring a selection in one request.
 *
 * The generated panel had bulk actions and the console that replaced it does
 * not, so clearing twenty photos out of a gallery is twenty confirmations. That
 * is the feature. What the tests are actually about is the answer it gives
 * back.
 *
 * A batch assembled from a list on screen is routinely stale by the time it is
 * sent: someone else removed a row, or the tab has been open since yesterday.
 * A single ok/failed for the whole call then has to either refuse ninety-nine
 * good ids over one bad one, or report success it did not have. Neither is
 * survivable on a screen whose entire purpose is telling the truth about what
 * is on the public website, so every id is answered on its own, and these tests
 * pin that shape as hard as they pin the deletes themselves.
 *
 * `delete` here is still the recoverable removal — the same soft delete the
 * single button does, the same audit rows, the same restore path back. There is
 * deliberately no bulk erase: the irreversible one is owner-only, one row at a
 * time, and is covered in AdminContentCollectionErasureTest.
 */
class AdminContentCollectionBulkTest extends TestCase
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

    /** @return array<int, array{id:int, ok:bool, message:?string}> results keyed by id */
    private function byId(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r['id']] = $r;
        }

        return $out;
    }

    // ---------------------------------------------------------------- access

    public function test_a_bulk_call_needs_an_admin_session(): void
    {
        $e = Event::create(['title' => 'Dhaka fair']);

        $this->postJson('/api/admin/content/events/bulk', ['action' => 'delete', 'ids' => [$e->id]])
            ->assertStatus(401);
        $this->assertNull($e->fresh()->deleted_at);
    }

    /** The same gate as the single button it batches: role, not just session. */
    public function test_partner_ops_staff_cannot_bulk_remove_website_content(): void
    {
        $e = Event::create(['title' => 'Dhaka fair']);
        $this->actingAs($this->staff(Role::StaffPartnerOps));

        $this->postJson('/api/admin/content/events/bulk', ['action' => 'delete', 'ids' => [$e->id]])
            ->assertStatus(403);
        $this->assertNull($e->fresh()->deleted_at);
    }

    public function test_bulk_is_a_flat_404_for_an_unknown_collection(): void
    {
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/users/bulk', ['action' => 'delete', 'ids' => [1]])
            ->assertStatus(404);
        $this->postJson('/api/admin/content/App%5CModels%5CUser/bulk', ['action' => 'delete', 'ids' => [1]])
            ->assertStatus(404);
    }

    /**
     * `bulk` occupies the slot `{id}` would, so the literal has to win. If the
     * router ever read it as an id this would land in update() or destroy()
     * instead, and a POST that means "remove twenty rows" would go somewhere
     * that has no idea what it was asked.
     */
    public function test_the_literal_is_not_mistaken_for_an_id(): void
    {
        $this->actingAs($this->staff());

        // A 422 from bulk's own validation, not a 404 from a missing row.
        $this->postJson('/api/admin/content/events/bulk', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action', 'ids']);
    }

    // ------------------------------------------------------------- the batch

    public function test_removing_a_selection_takes_all_of_it_off_the_list(): void
    {
        $keep = Event::create(['title' => 'Still on']);
        $a = Event::create(['title' => 'Cancelled A']);
        $b = Event::create(['title' => 'Cancelled B']);
        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/events/bulk', [
            'action' => 'delete',
            'ids' => [$a->id, $b->id],
        ])->assertOk();

        $this->assertSame(2, $res->json('requested'));
        $this->assertSame(2, $res->json('succeeded'));
        $this->assertSame(0, $res->json('failed'));

        $this->assertSame(
            ['Still on'],
            array_column($this->getJson('/api/admin/content/events')->json('data'), 'title')
        );
        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertNotNull($b->fresh()->deleted_at);
        $this->assertNull($keep->fresh()->deleted_at);

        // Still the recoverable removal, so each one is in the removed list.
        $this->assertCount(2, $this->getJson('/api/admin/content/events/trashed')->json('data'));
    }

    public function test_restoring_a_selection_puts_all_of_it_back(): void
    {
        $a = Event::create(['title' => 'Back A']);
        $b = Event::create(['title' => 'Back B']);
        $a->delete();
        $b->delete();
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/events/bulk', [
            'action' => 'restore',
            'ids' => [$a->id, $b->id],
        ])->assertOk()->assertJsonPath('succeeded', 2);

        $this->assertSame([], $this->getJson('/api/admin/content/events/trashed')->json('data'));
        $this->assertCount(2, $this->getJson('/api/admin/content/events')->json('data'));
    }

    /**
     * Putting pages back on the public site is a write to the public site,
     * however many at once. The restore button writes an audit row the model's
     * trait cannot — SoftDeletes fires `restored`, which nothing hooks — and a
     * batch that skipped it would make the bulk path the one way to change the
     * live site unanswerably.
     */
    public function test_every_row_a_batch_restores_is_recorded_by_name(): void
    {
        $a = Event::create(['title' => 'Back A']);
        $b = Event::create(['title' => 'Back B']);
        $a->delete();
        $b->delete();
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/events/bulk', [
            'action' => 'restore',
            'ids' => [$a->id, $b->id],
        ])->assertOk();

        $restored = ContentAuditLog::query()
            ->where('entity', 'events')->where('action', 'restore')
            ->pluck('entity_id')->all();

        $this->assertContains($a->legacy_id, $restored);
        $this->assertContains($b->legacy_id, $restored);
    }

    /** A removal in a batch is audited exactly as the single button's is. */
    public function test_every_row_a_batch_removes_is_recorded_too(): void
    {
        $a = Event::create(['title' => 'Cancelled A']);
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/events/bulk', ['action' => 'delete', 'ids' => [$a->id]])
            ->assertOk();

        $this->assertSame(
            1,
            ContentAuditLog::query()
                ->where('entity', 'events')->where('action', 'delete')
                ->where('entity_id', $a->legacy_id)->count()
        );
    }

    // ------------------------------------------------- partial, and honest

    /**
     * The case this shape exists for. One id was removed by someone else, one
     * never existed, and the rest are fine: the good ones must go through, and
     * the screen must be able to name the two that did not rather than showing
     * a tick over all four.
     */
    public function test_a_stale_id_is_reported_against_itself_and_does_not_stop_the_others(): void
    {
        $live = Event::create(['title' => 'Goes']);
        $alsoLive = Event::create(['title' => 'Also goes']);
        $already = Event::create(['title' => 'Someone got there first']);
        $already->delete();

        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/events/bulk', [
            'action' => 'delete',
            'ids' => [$live->id, 9999, $already->id, $alsoLive->id],
        ])->assertOk();

        $this->assertSame(4, $res->json('requested'));
        $this->assertSame(2, $res->json('succeeded'));
        $this->assertSame(2, $res->json('failed'));

        $results = $this->byId($res->json('results'));
        $this->assertTrue($results[$live->id]['ok']);
        $this->assertTrue($results[$alsoLive->id]['ok']);

        $this->assertFalse($results[9999]['ok']);
        $this->assertSame('That item no longer exists.', $results[9999]['message']);

        $this->assertFalse($results[$already->id]['ok']);
        $this->assertSame('That one had already been removed.', $results[$already->id]['message']);

        // The two good ones actually happened — a refusal in the batch must not
        // have rolled its neighbours back.
        $this->assertNotNull($live->fresh()->deleted_at);
        $this->assertNotNull($alsoLive->fresh()->deleted_at);
    }

    /**
     * deleted_at is what the removed list is ordered and dated by, so removing
     * something twice would quietly reset how long it has been in there. The
     * second attempt is refused rather than treated as a no-op that still saves.
     */
    public function test_removing_something_twice_does_not_move_when_it_was_removed(): void
    {
        $e = Event::create(['title' => 'Already gone']);
        $e->delete();
        $when = $e->fresh()->deleted_at;

        $this->travel(2)->days();
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/events/bulk', ['action' => 'delete', 'ids' => [$e->id]])
            ->assertOk()->assertJsonPath('succeeded', 0);

        $this->assertSame(
            $when->toDateTimeString(),
            Event::withTrashed()->find($e->id)->deleted_at->toDateTimeString()
        );
    }

    /** The restore half refuses in the same words its single-row handler does. */
    public function test_restoring_a_row_that_is_still_live_is_refused_per_id(): void
    {
        $live = Event::create(['title' => 'Never left']);
        $gone = Event::create(['title' => 'Actually removed']);
        $gone->delete();

        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/events/bulk', [
            'action' => 'restore',
            'ids' => [$live->id, $gone->id],
        ])->assertOk();

        $results = $this->byId($res->json('results'));
        $this->assertFalse($results[$live->id]['ok']);
        $this->assertSame(
            'That one has not been removed — it is still on the website.',
            $results[$live->id]['message'],
            'the same sentence the restore button gives, or the two look like different features'
        );
        $this->assertTrue($results[$gone->id]['ok']);
    }

    /** A selection scrolled through can honestly hold the same row twice. */
    public function test_a_duplicated_id_is_answered_once(): void
    {
        $e = Event::create(['title' => 'Selected twice']);
        $this->actingAs($this->staff());

        $res = $this->postJson('/api/admin/content/events/bulk', [
            'action' => 'delete',
            'ids' => [$e->id, $e->id],
        ])->assertOk();

        $this->assertSame(1, $res->json('requested'));
        $this->assertCount(1, $res->json('results'));
        $this->assertSame(1, $res->json('succeeded'));
    }

    // -------------------------------------------------------- what it refuses

    /**
     * The cap is the size of the mistake one request can make. A hundred is
     * more than these collections hold in practice, so anything larger is far
     * likelier to be a runaway loop than a person clearing a gallery.
     */
    public function test_a_batch_larger_than_the_cap_is_refused_whole(): void
    {
        $e = Event::create(['title' => 'Survives']);
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/events/bulk', [
            'action' => 'delete',
            'ids' => array_merge([$e->id], range(1000, 1100)),
        ])->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->assertNull($e->fresh()->deleted_at, 'a refused batch must not have done part of itself');
    }

    /**
     * The action is allow-listed, which is also what keeps the irreversible one
     * out of here: erasing is owner-only and one row at a time, and must not
     * become reachable by renaming a string in a bulk payload.
     */
    public function test_only_delete_and_restore_are_accepted(): void
    {
        $e = Event::create(['title' => 'Survives']);
        $e->delete();
        $this->actingAs($this->staff(Role::SuperAdmin));

        foreach (['force', 'forceDelete', 'erase', 'update', ''] as $action) {
            $this->postJson('/api/admin/content/events/bulk', ['action' => $action, 'ids' => [$e->id]])
                ->assertStatus(422)->assertJsonValidationErrors('action');
        }

        $this->assertNotNull(
            Event::withTrashed()->find($e->id),
            'no bulk action may erase a row, whatever it is called'
        );
    }

    public function test_an_id_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/events/bulk', ['action' => 'delete', 'ids' => ['1; DROP TABLE events']])
            ->assertStatus(422)->assertJsonValidationErrors('ids.0');

        $this->postJson('/api/admin/content/events/bulk', ['action' => 'delete', 'ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('ids');

        // Still there, which is the point of the first case.
        $this->assertSame(0, Event::query()->count());
        $this->getJson('/api/admin/content/events')->assertOk();
    }

    /** Not events-only: the batch runs through the same allow-list as everything else. */
    public function test_another_collection_batches_the_same_way(): void
    {
        $a = Photo::create(['caption' => 'One']);
        $b = Photo::create(['caption' => 'Two']);
        $this->actingAs($this->staff());

        $this->postJson('/api/admin/content/photos/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('succeeded', 2);

        $this->assertSame([], $this->getJson('/api/admin/content/photos')->json('data'));
    }
}
