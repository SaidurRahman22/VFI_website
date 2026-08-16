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
}
