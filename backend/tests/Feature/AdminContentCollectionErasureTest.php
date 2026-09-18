<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Content\Event;
use App\Models\Content\Photo;
use App\Models\ContentAuditLog;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Erasing a content row for good — the one thing no interface could do.
 *
 * Every removal this project has ever recorded is still in its table. destroy()
 * soft-deletes, restore() undoes it, and nothing anywhere finished the job, so
 * the tables and the images they pin on disk only ever grow. That is the gap
 * this covers.
 *
 * The tests here weigh the refusals more heavily than the success, because an
 * erasure that works is worth far less than an erasure that cannot be reached
 * by accident:
 *
 *   OWNER, NOT EDITOR. Publishing and unpublishing is a content editor's job
 *   and is recoverable. This is not recoverable, so it takes the account that
 *   answers for the site — the same isSuperAdmin() gate the page toggle and the
 *   backup restore use. A refused attempt must also leave the row exactly where
 *   it was, which is asserted separately: a 403 that erased it anyway would be
 *   the worst possible bug in this file.
 *
 *   REMOVED FIRST. A live row cannot be erased in one call. The soft delete in
 *   between is not a formality — it is the state in which the person can still
 *   read the list, recognise the mistake and put it back.
 *
 *   WRITTEN DOWN BEFORE IT GOES. The audit row is the only thing that outlives
 *   the content, so it has to carry enough of the row to say what was destroyed,
 *   and it has to be distinguishable from the recoverable removal that came
 *   first — the two are the same act to the model's audit trait.
 *
 *   THE IMAGE IS REFERENCE-COUNTED, STILL. Ids are content hashes, so two rows
 *   with the same picture share one file. Erasing one of them must not blank
 *   the other.
 */
class AdminContentCollectionErasureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Nothing here may touch a real public disk: the subject of half these
        // tests is a file being deleted.
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

    /** A row already in the state the erase route requires. */
    private function removedEvent(array $attributes = []): Event
    {
        $e = Event::create($attributes + ['title' => 'Cancelled fair']);
        $e->delete();

        return $e;
    }

    // ------------------------------------------------------------- refusals

    public function test_erasing_needs_an_admin_session(): void
    {
        $e = $this->removedEvent();

        $this->deleteJson("/api/admin/content/events/{$e->id}/force")->assertStatus(401);
        $this->assertNotNull(Event::withTrashed()->find($e->id));
    }

    /**
     * A content editor may take any page off the site and put it back. That is
     * the authority the whole controller is gated on, and it is deliberately
     * not enough for this: the difference between the two acts is that one of
     * them cannot be undone by anybody.
     */
    public function test_a_content_editor_cannot_erase_what_it_may_remove(): void
    {
        $e = $this->removedEvent();
        $this->actingAs($this->staff(Role::ContentEditor));

        // It could remove it — that is the point of the comparison.
        $this->getJson('/api/admin/content/events/trashed')->assertOk();

        $this->deleteJson("/api/admin/content/events/{$e->id}/force")->assertStatus(403);
        $this->assertNotNull(
            Event::withTrashed()->find($e->id),
            'a refused erasure must not have erased it anyway'
        );
    }

    /** Partner-ops staff hold an admin session and fail the ability gate first. */
    public function test_other_staff_cannot_erase_either(): void
    {
        $e = $this->removedEvent();
        $this->actingAs($this->staff(Role::StaffPartnerOps));

        $this->deleteJson("/api/admin/content/events/{$e->id}/force")->assertStatus(403);
        $this->assertNotNull(Event::withTrashed()->find($e->id));
    }

    /**
     * One mis-click on a list of live content must not be able to destroy
     * anything. Erasing acts only on something already removed, so it always
     * takes two decisions with a recoverable state in between.
     */
    public function test_a_live_row_cannot_be_erased_in_one_call(): void
    {
        $e = Event::create(['title' => 'Still on the site']);
        $this->actingAs($this->staff(Role::SuperAdmin));

        $res = $this->deleteJson("/api/admin/content/events/{$e->id}/force")->assertStatus(422);

        $this->assertStringContainsString('Remove this from the website first', $res->json('message'));
        $this->assertNotNull(Event::query()->find($e->id), 'the live row must still be live');
        $this->assertSame(
            0,
            ContentAuditLog::query()->where('action', 'force_delete')->count(),
            'a refusal must not write an audit row for something that did not happen'
        );
    }

    public function test_an_id_nobody_created_is_the_same_404_as_everywhere_else(): void
    {
        $this->actingAs($this->staff(Role::SuperAdmin));

        $this->deleteJson('/api/admin/content/events/9999/force')->assertStatus(404);
    }

    /** The erase route resolves its slug through the same allow-list. */
    public function test_erasing_is_a_flat_404_for_an_unknown_collection(): void
    {
        $this->actingAs($this->staff(Role::SuperAdmin));

        $this->deleteJson('/api/admin/content/users/1/force')->assertStatus(404);
        $this->deleteJson('/api/admin/content/App%5CModels%5CUser/1/force')->assertStatus(404);
    }

    // --------------------------------------------------------- what it does

    public function test_erasing_removes_the_row_from_the_database_for_good(): void
    {
        $e = $this->removedEvent();
        $this->actingAs($this->staff(Role::SuperAdmin));

        $this->assertCount(1, $this->getJson('/api/admin/content/events/trashed')->json('data'));

        $this->deleteJson("/api/admin/content/events/{$e->id}/force")
            ->assertOk()
            ->assertJsonPath('erased', $e->id);

        $this->assertNull(
            Event::withTrashed()->find($e->id),
            'withTrashed must not find it either — that is the difference from a removal'
        );
        $this->assertSame([], $this->getJson('/api/admin/content/events/trashed')->json('data'));

        // And it is gone, so the same call again has nothing to act on.
        $this->deleteJson("/api/admin/content/events/{$e->id}/force")->assertStatus(404);
    }

    /**
     * The audit row is the only thing that outlives the content, so it carries
     * what the row said. It also has to be findable AS an erasure: the model's
     * trait writes its own `delete` row here, identical in shape to the one the
     * recoverable removal already wrote, and "taken off the site" versus "gone"
     * is the entire question someone opens an audit log to answer.
     */
    public function test_what_was_erased_is_written_down_before_it_goes(): void
    {
        $e = $this->removedEvent(['title' => 'Dhaka spot day', 'city' => 'Dhaka']);
        $legacyId = $e->legacy_id;
        $owner = $this->staff(Role::SuperAdmin);
        $this->actingAs($owner);

        $this->deleteJson("/api/admin/content/events/{$e->id}/force")->assertOk();

        $row = ContentAuditLog::query()
            ->where('action', 'force_delete')
            ->where('entity', 'events')
            ->where('entity_id', $legacyId)
            ->first();

        $this->assertNotNull($row, 'an erasure with no audit row is one nobody can be asked about');
        $this->assertSame($owner->id, $row->actor_user_id);
        $this->assertSame('Dhaka spot day', $row->before['title'] ?? null);
        $this->assertSame('Dhaka', $row->before['city'] ?? null);
        $this->assertNull($row->after);
    }

    // ------------------------------------------------------------- the file

    /**
     * Image ids are content hashes, so the same picture on two rows is one file
     * on disk. Erasing one of those rows must leave the other's card intact —
     * which is the same reason destroy() never touches the file at all.
     */
    public function test_a_shared_image_survives_until_the_last_row_using_it_is_erased(): void
    {
        $id = '/storage/media/'.str_repeat('a', 64).'.jpg';
        Storage::disk('public')->put('media/'.basename($id), 'jpeg-bytes');

        $one = Photo::create(['caption' => 'Graduation', 'img_id' => $id]);
        $two = Photo::create(['caption' => 'The same photo again', 'img_id' => $id]);
        $one->delete();
        $two->delete();

        $this->actingAs($this->staff(Role::SuperAdmin));

        $this->deleteJson("/api/admin/content/photos/{$one->id}/force")->assertOk();
        // Storage's assertExists takes expected CONTENT as its second argument,
        // not a message, so the reason lives here instead: another row still
        // shows this picture.
        Storage::disk('public')->assertExists('media/'.basename($id));

        $this->deleteJson("/api/admin/content/photos/{$two->id}/force")->assertOk();
        Storage::disk('public')->assertMissing('media/'.basename($id));
    }

    /**
     * A removed row still counts as a reference, because restoring it would
     * need the picture back. So the file may only go once the erasure itself
     * has taken the last reference away — releasing it a moment earlier would
     * find the row still holding its own image and leave the file on disk for
     * ever, which is the accumulation this route exists to stop.
     */
    public function test_a_row_still_in_the_removed_list_keeps_its_image(): void
    {
        $id = '/storage/media/'.str_repeat('b', 64).'.jpg';
        Storage::disk('public')->put('media/'.basename($id), 'jpeg-bytes');

        $keep = Photo::create(['caption' => 'Still recoverable', 'img_id' => $id]);
        $erase = Photo::create(['caption' => 'Not recoverable', 'img_id' => $id]);
        $keep->delete();
        $erase->delete();

        $this->actingAs($this->staff(Role::SuperAdmin));
        $this->deleteJson("/api/admin/content/photos/{$erase->id}/force")->assertOk();

        Storage::disk('public')->assertExists('media/'.basename($id));
        $this->postJson("/api/admin/content/photos/{$keep->id}/restore")->assertOk();
        $this->assertSame($id, $keep->fresh()->img_id, 'the restored row must still have its picture');
    }

    /** A media slot pointing at the image is a reference like any other. */
    public function test_an_image_a_media_slot_still_uses_is_not_deleted(): void
    {
        $id = '/storage/media/'.str_repeat('c', 64).'.jpg';
        Storage::disk('public')->put('media/'.basename($id), 'jpeg-bytes');
        SiteContent::query()->updateOrCreate(['key' => 'media'], ['value' => ['home_hero' => $id], 'version' => 1]);

        $p = Photo::create(['caption' => 'Also the hero', 'img_id' => $id]);
        $p->delete();

        $this->actingAs($this->staff(Role::SuperAdmin));
        $this->deleteJson("/api/admin/content/photos/{$p->id}/force")->assertOk();

        Storage::disk('public')->assertExists('media/'.basename($id));
    }

    /**
     * Bundled artwork lives in the repository, not in the upload store, and a
     * row referencing it is referencing a file the site ships. Deleting one
     * would blank a page that no editor ever touched.
     */
    public function test_bundled_artwork_is_never_deleted_by_an_erasure(): void
    {
        Storage::disk('public')->put('assets/img/campus.jpg', 'shipped-with-the-site');

        $p = Photo::create(['caption' => 'Campus', 'img_id' => 'assets/img/campus.jpg']);
        $p->delete();

        $this->actingAs($this->staff(Role::SuperAdmin));
        $this->deleteJson("/api/admin/content/photos/{$p->id}/force")->assertOk();

        Storage::disk('public')->assertExists('assets/img/campus.jpg');
    }

    /** Six of the ten collections have no image column at all. */
    public function test_a_collection_without_an_image_erases_cleanly(): void
    {
        $this->actingAs($this->staff(Role::SuperAdmin));

        $id = $this->postJson('/api/admin/content/pp-quicklinks', ['label' => 'A link'])
            ->assertCreated()->json('item.id');
        $this->deleteJson("/api/admin/content/pp-quicklinks/{$id}")->assertOk();

        $this->deleteJson("/api/admin/content/pp-quicklinks/{$id}/force")->assertOk();
    }
}
