<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Catalogue\Institution;
use App\Models\ContentAuditLog;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `universityPage` in the console's own singleton editor.
 *
 * It was the last content in the project that only Filament could edit: it was
 * not in AdminContentController::EDITABLE at all, so the console answered 404
 * for it and "University page defaults" had to survive as a Filament screen.
 * That copy is not decoration — it is the intake cards, the cost paragraph and
 * the FAQ set that every university page shows when the university itself has
 * nothing, so it appears on more public pages than any other singleton.
 *
 * What is worth asserting beyond "it saves":
 *
 *   IT MUST BE A GROUPED KEY, NOT A FLAT ONE. Three of the seven things it
 *   holds are rows. A console that got `sections` back for this key would
 *   render four textareas and then save them over the seasons, the FAQs and
 *   the lead-form options on the first click.
 *
 *   THE SHAPE HAS TO MATCH THE READER. The Filament page has been writing this
 *   key for months and PublicUniversityController::pageDefaults() reads it. If
 *   the console writes a different shape the page silently falls back to its
 *   built-in wording and nobody sees an error, so the round trip is asserted
 *   all the way out to the public endpoint rather than to the database.
 *
 *   IT MUST NOT WIDEN WHO MAY EDIT IT. The Filament screen gates on
 *   catalogue.manage; this endpoint gates on canEditContent(). Both resolve to
 *   content editor plus superadmin today, and a counsellor hired to process
 *   applications must not gain the public site by this new door.
 */
class AdminUniversityPageEditorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every default pageDefaults() serves, and therefore every one the editor
     * has to offer a field for. Hardcoded on purpose: reading it back off the
     * schema under test would assert only that the schema equals itself.
     */
    private const SERVED = [
        'seasons', 'intake_footnote', 'cost_intro', 'cost_footnote',
        'scholarship_note', 'faqs', 'interest_options',
    ];

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

    public function test_the_key_is_no_longer_a_404_for_the_console(): void
    {
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/content/singleton/universityPage')->assertOk();
    }

    /**
     * The role the Filament screen's gate was written for. This endpoint is a
     * second way in to the same copy, so it has to refuse the same people.
     */
    public function test_a_counsellor_can_neither_read_nor_write_it(): void
    {
        $this->actingAs($this->staff(Role::StaffCounsellor));

        $this->getJson('/api/admin/content/singleton/universityPage')->assertStatus(403);
        $this->putJson('/api/admin/content/singleton/universityPage', [
            'version' => 0, 'value' => ['cost_intro' => 'Should never be written.'],
        ])->assertStatus(403);

        $this->assertNull(SiteContent::value('universityPage'));
    }

    public function test_signed_out_is_blocked(): void
    {
        $this->getJson('/api/admin/content/singleton/universityPage')->assertStatus(401);
    }

    // ---------------------------------------------------------------- schema

    public function test_it_is_offered_as_repeating_blocks_and_never_as_a_flat_form(): void
    {
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/content/singleton/universityPage')->assertOk();

        $this->assertNull($res->json('sections'), 'a flat form here would save over the rows it could not show');
        $this->assertNotNull($res->json('grouped'));
        $this->assertSame([], $res->json('grouped.groups'), 'one set of defaults serves every university');
        $this->assertSame('University page defaults', $res->json('label'));
        $this->assertNotEmpty($res->json('empty_means'), 'an empty field here keeps the built-in wording, and the screen must say so');
    }

    /**
     * A default with no input is a setting the console cannot reach, which is
     * the whole gap this key was added to close.
     */
    public function test_every_default_the_public_page_reads_has_a_field_to_edit_it(): void
    {
        $this->actingAs($this->staff());

        $g = $this->getJson('/api/admin/content/singleton/universityPage')->assertOk()->json('grouped');

        $described = array_merge(
            array_column($g['fields'], 'key'),
            array_column($g['lists'], 'key'),
        );
        sort($described);
        $served = self::SERVED;
        sort($served);

        $this->assertSame($served, $described);

        foreach ($g['fields'] as $f) {
            $this->assertNotEmpty($f['label'], $f['key'].' needs a label a person can read');
        }
        foreach ($g['lists'] as $list) {
            $this->assertNotEmpty($list['label']);
            $this->assertNotEmpty($list['singular'], 'the "Add …" button is built from this');
            foreach ($list['item'] as $f) {
                $this->assertNotEmpty($f['label'], $list['key'].'.'.$f['key'].' needs a label a person can read');
            }
        }
    }

    /** The row keys pageDefaults() picks out of each list, one for one. */
    public function test_each_repeating_block_offers_the_row_fields_the_reader_uses(): void
    {
        $this->actingAs($this->staff());

        $lists = collect($this->getJson('/api/admin/content/singleton/universityPage')->assertOk()->json('grouped.lists'))
            ->keyBy('key');

        $this->assertSame(['key', 'month', 'note', 'image'], array_column($lists['seasons']['item'], 'key'));
        $this->assertSame(['q', 'a'], array_column($lists['faqs']['item'], 'key'));
        $this->assertSame(['label'], array_column($lists['interest_options']['item'], 'key'));
    }

    // ----------------------------------------------------------------- write

    public function test_saving_writes_it_bumps_the_version_and_is_audited(): void
    {
        SiteContent::query()->create(['key' => 'universityPage', 'version' => 1,
            'value' => ['cost_intro' => 'Old copy.']]);
        $this->actingAs($this->staff());

        $res = $this->putJson('/api/admin/content/singleton/universityPage', [
            'version' => 1,
            'value' => ['cost_intro' => 'Costs at {university} vary by course.'],
        ])->assertOk();

        $this->assertSame(2, $res->json('version'));
        $this->assertSame('Costs at {university} vary by course.', SiteContent::value('universityPage')['cost_intro']);
        $this->assertSame(1, ContentAuditLog::where('entity', 'site_content')->where('action', 'update')->count());
    }

    /** Two editors with the screen open: the second must lose visibly. */
    public function test_a_stale_save_is_refused_rather_than_overwriting(): void
    {
        SiteContent::query()->create(['key' => 'universityPage', 'version' => 1,
            'value' => ['cost_intro' => 'original']]);
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/universityPage', [
            'version' => 1, 'value' => ['cost_intro' => 'from editor A'],
        ])->assertOk();

        $this->putJson('/api/admin/content/singleton/universityPage', [
            'version' => 1, 'value' => ['cost_intro' => 'from editor B'],
        ])->assertStatus(409)->assertJsonPath('currentVersion', 2);

        $this->assertSame('from editor A', SiteContent::value('universityPage')['cost_intro']);
    }

    /**
     * The rows survive the trip. A screen that flattened them would lose the
     * intake cards and the FAQ set, and the public page would quietly show its
     * built-in wording instead of erroring, so this has to be checked.
     */
    public function test_the_repeating_blocks_round_trip_through_the_editor(): void
    {
        $this->actingAs($this->staff());

        $value = [
            'intake_footnote' => 'Apply early — places close before the published deadline.',
            'cost_intro' => 'Costs at {university} vary by course.',
            'cost_footnote' => 'Indicative figures only.',
            'scholarship_note' => 'Ask us what {university} funds this year.',
            'seasons' => [
                ['key' => 'fall', 'month' => 'September', 'note' => 'The main intake.', 'image' => 'media/universities/intakes/fall.jpg'],
                ['key' => 'spring', 'month' => 'January', 'note' => 'The second intake.', 'image' => ''],
            ],
            'faqs' => [['q' => 'Is there an application fee?', 'a' => 'It varies by course.']],
            'interest_options' => [['label' => "Master's"], ['label' => 'MBA']],
        ];

        $this->putJson('/api/admin/content/singleton/universityPage', ['version' => 0, 'value' => $value])
            ->assertOk()->assertJsonPath('version', 1);

        $back = $this->getJson('/api/admin/content/singleton/universityPage')->assertOk();

        $this->assertSame($value, $back->json('value'));
        $this->assertSame(1, $back->json('version'));
    }

    /**
     * The one that proves the gap is closed. Everything above would still pass
     * if the console wrote a shape pageDefaults() cannot read, because a
     * mismatch there is silent — the page just falls back. So: save the way the
     * console saves, then read the public endpoint a visitor reads.
     */
    public function test_what_the_console_saves_is_what_a_university_page_serves(): void
    {
        config([
            'catalogue.seed.universities_per_country' => 2,
            'catalogue.seed.programs_per_university' => 3,
            'catalogue.seed.base_year' => 2026,
        ]);
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        $this->actingAs($this->staff());
        $this->putJson('/api/admin/content/singleton/universityPage', ['version' => 0, 'value' => [
            'intake_footnote' => 'Apply early.',
            'cost_intro' => 'Costs at {university} vary.',
            'cost_footnote' => 'Indicative only.',
            'scholarship_note' => 'Ask us about {university} funding.',
            'seasons' => [['key' => 'fall', 'month' => 'Sept', 'note' => 'Main intake', 'image' => 'media/x/fall.jpg']],
            'faqs' => [['q' => 'Default Q?', 'a' => 'Default A.']],
            'interest_options' => [['label' => "Master's"], ['label' => 'MBA']],
        ]])->assertOk();

        $id = Institution::query()->value('id');

        $this->getJson("/api/universities/{$id}")->assertOk()
            ->assertJsonPath('defaults.seasons.fall.month', 'Sept')
            ->assertJsonPath('defaults.seasons.fall.image', '/storage/media/x/fall.jpg')
            ->assertJsonPath('defaults.intake_footnote', 'Apply early.')
            ->assertJsonPath('defaults.cost_intro', 'Costs at {university} vary.')
            ->assertJsonPath('defaults.cost_footnote', 'Indicative only.')
            ->assertJsonPath('defaults.scholarship_note', 'Ask us about {university} funding.')
            ->assertJsonPath('defaults.faqs.0.q', 'Default Q?')
            ->assertJsonPath('defaults.interest_options.1', 'MBA');
    }

    /**
     * The console merges its form over the value it loaded, so a key the schema
     * has not caught up with must survive a save rather than being dropped.
     */
    public function test_a_key_the_schema_does_not_describe_is_kept(): void
    {
        SiteContent::query()->create(['key' => 'universityPage', 'version' => 1,
            'value' => ['cost_intro' => '1', 'someFutureKey' => 'keep me']]);
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/universityPage', [
            'version' => 1,
            'value' => ['cost_intro' => '2', 'someFutureKey' => 'keep me'],
        ])->assertOk();

        $this->assertSame('keep me', SiteContent::value('universityPage')['someFutureKey']);
    }
}
