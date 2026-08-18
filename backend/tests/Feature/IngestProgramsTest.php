<?php

namespace Tests\Feature;

use App\Models\Catalogue\Institution;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramIntake;
use App\Models\Catalogue\ProgramRequirement;
use App\Models\TaxonomyTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IngestProgramsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // freeze time so staleness (past-deadline) assertions are date-independent
        $this->travelTo('2026-08-15');
        // small deterministic catalogue for fast assertions
        config([
            'catalogue.seed.universities_per_country' => 2,
            'catalogue.seed.programs_per_university' => 3,
            'catalogue.seed.base_year' => 2026,
        ]);
    }

    public function test_seed_ingest_builds_relational_and_search_rows(): void
    {
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        // 5 no-free-feed countries × 2 unis, 3 programs each, 3 intakes each
        $this->assertSame(10, Institution::count());
        $this->assertSame(30, Program::count());
        $this->assertSame(90, ProgramIntake::count());
        $this->assertGreaterThanOrEqual(30, ProgramRequirement::count());
        $this->assertSame(30, Program::where('source', 'seed')->count());

        // one search row per program-intake
        $this->assertSame(90, DB::table('program_search')->count());
        // each seeded country: 2 unis × 3 programs × 3 intakes = 18 rows
        $this->assertSame(18, DB::table('program_search')->where('country', 'United Kingdom')->count());
        $this->assertSame(18, DB::table('program_search')->where('country', 'New Zealand')->count());
    }

    public function test_facet_flags_are_encoded_as_padded_tokens(): void
    {
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        $total = DB::table('program_search')->count();

        // universal seed tokens: every row is vfi-represented, open, and (no maths req) waivable
        $this->assertSame($total, DB::table('program_search')->where('flags', 'like', '% vfi %')->count());
        $this->assertSame($total, DB::table('program_search')->where('flags', 'like', '% open %')->count());
        $this->assertSame($total, DB::table('program_search')->where('flags', 'like', '% waive_maths %')->count());

        // some rows carry the discriminating facets
        $this->assertGreaterThan(0, DB::table('program_search')->where('flags', 'like', '% stem %')->count());
        $this->assertGreaterThan(0, DB::table('program_search')->where('flags', 'like', '% scholarship %')->count());
        $this->assertGreaterThan(0, DB::table('program_search')->where('flags', 'like', '% waive_english %')->count());

        // tokens are space-padded so a search binds '% token %' with no substring bleed
        $sample = DB::table('program_search')->first();
        $this->assertStringStartsWith(' ', $sample->flags);
        $this->assertStringEndsWith(' ', $sample->flags);
    }

    public function test_past_deadline_intakes_are_flagged_stale(): void
    {
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        // base_year Fall deadline (2026-07-01) is past on today's date → stale;
        // the two next-year intakes are still open.
        $this->assertSame(30, DB::table('program_search')->where('is_stale', 1)->count());
        $this->assertSame(60, DB::table('program_search')->where('is_stale', 0)->count());
    }

    public function test_reingest_is_idempotent(): void
    {
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        // upsert on (source, external_ref) — no duplication on a second run
        $this->assertSame(30, Program::count());
        $this->assertSame(90, ProgramIntake::count());
        $this->assertSame(90, DB::table('program_search')->count());
    }

    public function test_feed_soft_fill_never_overwrites_staff_edits(): void
    {
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        // staff author a placement note + website in the admin
        $inst = Institution::query()->firstOrFail();
        $inst->forceFill([
            'website' => 'https://staff-edited.example',
            'salary_note' => 'Staff wrote this',
        ])->save();

        // a later feed run must leave both alone
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        $inst->refresh();
        $this->assertSame('https://staff-edited.example', $inst->website);
        $this->assertSame('Staff wrote this', $inst->salary_note);
    }

    public function test_allow_list_rejects_values_not_in_the_taxonomy(): void
    {
        // retire the Bachelor level → every seeded Bachelor program must be refused
        TaxonomyTerm::where('kind', 'level')->where('value', 'bachelor')->update(['active' => false]);

        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();

        $this->assertSame(0, Program::where('level', 'bachelor')->count());
        $this->assertLessThan(30, Program::count()); // the Bachelor records were dropped
    }

    /**
     * The client's requirement, as a test: every figure a feed writes must be an
     * ordinary editable field, and typing over it in the admin must stick.
     *
     * These are the detail-page sections that rendered EMPTY on live while the
     * published figures sat one API field away - Cost to study, Admissions and
     * the About paragraph. They are soft-filled, so the feed writes them once and
     * never again touches them after a human has.
     */
    public function test_cost_admissions_and_overview_are_soft_filled_and_then_staff_owned(): void
    {
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();
        $inst = Institution::query()->firstOrFail();

        // The seed feed supplies none of these, so they start empty - which is
        // itself the point: an empty field is honest, a fabricated one is not.
        $this->assertNull($inst->cost_note);
        $this->assertNull($inst->living_cost_note);
        $this->assertNull($inst->admission_academic);

        // Staff fill them in the admin panel.
        $inst->forceFill([
            'overview' => 'Written by the VFI content team.',
            'cost_note' => 'Confirmed with the admissions office, March 2026.',
            'cost_rows_json' => [['label' => 'Tuition', 'value' => 'GBP 18,000']],
            'living_cost_note' => 'GBP 1,100 per month',
            'admission_academic' => 'Second class upper honours or equivalent',
            'admission_english' => 'IELTS 6.5 with no band below 6.0',
        ])->save();

        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();
        $inst->refresh();

        $this->assertSame('Written by the VFI content team.', $inst->overview);
        $this->assertSame('Confirmed with the admissions office, March 2026.', $inst->cost_note);
        $this->assertSame([['label' => 'Tuition', 'value' => 'GBP 18,000']], $inst->cost_rows_json);
        $this->assertSame('GBP 1,100 per month', $inst->living_cost_note);
        $this->assertSame('Second class upper honours or equivalent', $inst->admission_academic);
        $this->assertSame('IELTS 6.5 with no band below 6.0', $inst->admission_english);
    }

    /**
     * Every field a feed can write must exist on the admin form, or the client
     * cannot see or correct it. Asserted against the form source rather than a
     * rendered page, because a missing component is a wiring mistake, not a
     * rendering one - and this is the check that would have caught the Cost to
     * Study section being unreachable.
     */
    public function test_every_feed_written_field_is_editable_in_the_admin(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/Universities/Schemas/UniversityForm.php'));

        foreach ([
            'overview', 'overview_stats_json', 'cost_note', 'cost_rows_json',
            'living_cost_note', 'admission_academic', 'admission_english',
            'salary_note', 'placement_note', 'website',
        ] as $field) {
            $this->assertStringContainsString(
                "make('".$field."')",
                $form,
                "{$field} is written by a feed but has no input on the university form, so nobody can correct it."
            );
        }

        // and each block must say where on the public site it appears
        $this->assertGreaterThanOrEqual(13, substr_count($form, '->description('),
            'every Section needs a description telling the editor which part of the site it drives');
    }
}
