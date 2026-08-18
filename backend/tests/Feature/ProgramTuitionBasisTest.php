<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Catalogue\Program;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `programs.tuition_fee_minor` holds two different kinds of number. DAAD reports
 * a fee per course; the U.S. College Scorecard publishes ONE annual tuition per
 * school, and the ingest stamped that single figure onto every programme it
 * found there — which is why count(distinct tuition_fee_minor) per scorecard
 * institution is exactly 1. Search rendered both as "Tuition", so an
 * institution-wide average reached a counsellor as this programme's price.
 *
 * These tests pin the fix end to end: the source declares the basis, the ingest
 * stores it, the index carries it, the API ships it beside the amount, and the
 * migration relabels the rows written before the column existed.
 */
class ProgramTuitionBasisTest extends TestCase
{
    use RefreshDatabase;

    /** Must track the migration; see 2026_08_18_000010. */
    private const BASIS_MAX = 32;

    private const MIGRATION = 'migrations/2026_08_18_000010_add_tuition_basis_to_programs.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-15');
        config([
            'catalogue.scorecard.max_institutions' => 1,
            'catalogue.scorecard.per_page' => 1,
            'catalogue.daad.max' => 1,
            'catalogue.seed.base_year' => 2026,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_scorecard_ingest_records_its_tuition_as_an_institution_average(): void
    {
        $this->fakeFeeds();
        $this->artisan('programs:ingest', ['--source' => 'scorecard'])->assertSuccessful();

        $programs = Program::where('source', 'scorecard')->get();

        // the shape of the problem: two unrelated courses, one identical price
        $this->assertCount(2, $programs);
        $this->assertSame(1, $programs->pluck('tuition_fee_minor')->unique()->count());
        $this->assertSame(3313900, (int) $programs->first()->tuition_fee_minor);

        foreach ($programs as $program) {
            $this->assertSame('institution_average', $program->tuition_basis);
        }

        // and the flat search row the API actually reads carries it too
        $this->assertGreaterThan(0, DB::table('program_search')->where('source', 'scorecard')->count());
        $this->assertSame(0, DB::table('program_search')
            ->where('source', 'scorecard')->where('tuition_basis', '!=', 'institution_average')->count());
    }

    public function test_daad_ingest_keeps_its_tuition_as_a_programme_fee(): void
    {
        $this->fakeFeeds();
        $this->artisan('programs:ingest', ['--source' => 'daad'])->assertSuccessful();

        $program = Program::where('source', 'daad')->firstOrFail();

        // German public universities are mostly tuition-free, so DAAD legitimately
        // reports one repeated value. That is a real per-course fee of zero, not an
        // average, and nothing here may relabel it.
        $this->assertSame('programme', $program->tuition_basis);
        $this->assertSame(0, (int) $program->tuition_fee_minor);
        $this->assertSame(0, DB::table('program_search')
            ->where('source', 'daad')->where('tuition_basis', '!=', 'programme')->count());
    }

    public function test_search_api_ships_the_basis_beside_the_amount(): void
    {
        $this->ingestBothFeeds();

        $us = $this->partner()->getJson('/api/partner/programs/search?country=United+States')
            ->assertStatus(200)->json('data');
        $this->assertNotEmpty($us);
        foreach ($us as $row) {
            $this->assertSame('institution_average', $row['tuition']['basis']);
        }

        $de = $this->partner()->getJson('/api/partner/programs/search?country=Germany')
            ->assertStatus(200)->json('data');
        $this->assertNotEmpty($de);
        foreach ($de as $row) {
            $this->assertSame('programme', $row['tuition']['basis']);
        }
    }

    public function test_detail_and_compare_endpoints_expose_the_basis(): void
    {
        $this->ingestBothFeeds();

        $us = Program::where('source', 'scorecard')->firstOrFail();
        $de = Program::where('source', 'daad')->firstOrFail();

        $this->partner()->getJson('/api/partner/programs/'.$us->id)
            ->assertStatus(200)
            ->assertJsonPath('program.tuition.basis', 'institution_average');

        // the compare grid is where an average and a real fee sit side by side
        $rows = $this->partner()->getJson('/api/partner/programs/compare?ids='.$us->id.','.$de->id)
            ->assertStatus(200)->json('data');

        $this->assertSame('institution_average', $rows[0]['tuition']['basis']);
        $this->assertSame('programme', $rows[1]['tuition']['basis']);
    }

    /**
     * The 40,445 scorecard programmes already live were written before the column
     * existed, so they took the 'programme' default. Re-ingesting is not an option
     * (the feed allows ~30 requests an hour), so the migration relabels them in
     * place — this proves it does, and that it leaves the other feeds alone.
     */
    public function test_the_backfill_relabels_rows_written_before_the_column_existed(): void
    {
        $this->ingestBothFeeds();

        // rewind both tables to the pre-migration state (everything defaulted)
        DB::table('programs')->update(['tuition_basis' => 'programme']);
        DB::table('program_search')->update(['tuition_basis' => 'programme']);

        // up() is hasColumn-guarded, so re-running it exercises only the backfill
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertSame(0, Program::where('source', 'scorecard')
            ->where('tuition_basis', '!=', 'institution_average')->count());
        $this->assertGreaterThan(0, DB::table('program_search')
            ->where('source', 'scorecard')->where('tuition_basis', 'institution_average')->count());

        // DAAD is a real per-course fee and must survive untouched
        $this->assertSame('programme', Program::where('source', 'daad')->firstOrFail()->tuition_basis);
        $this->assertSame(0, DB::table('program_search')
            ->where('source', 'daad')->where('tuition_basis', '!=', 'programme')->count());
    }

    /**
     * Postgres enforces varchar length and SQLite does not, so a column sized to
     * today's longest value is invisible here and a 500 in production — exactly
     * how content_audit_log.action failed (see ContentAuditLogWidthTest). The
     * longest value, 'institution_average', is 20 chars and would have filled a
     * varchar(20) with nothing left for the next basis we learn about.
     */
    public function test_the_basis_column_is_wide_enough_for_its_vocabulary(): void
    {
        $sql = file_get_contents(database_path(self::MIGRATION));
        $this->assertStringContainsString("string('tuition_basis', ".self::BASIS_MAX.')', $sql);

        foreach (['programme', 'institution_average'] as $value) {
            $this->assertLessThan(self::BASIS_MAX, strlen($value));
        }

        // and the longest one survives a round trip through the database
        $this->fakeFeeds();
        $this->artisan('programs:ingest', ['--source' => 'scorecard'])->assertSuccessful();
        $this->assertSame('institution_average', Program::where('source', 'scorecard')->firstOrFail()->tuition_basis);
    }

    private function ingestBothFeeds(): void
    {
        $this->fakeFeeds();
        $this->artisan('programs:ingest', ['--source' => 'scorecard'])->assertSuccessful();
        $this->artisan('programs:ingest', ['--source' => 'daad'])->assertSuccessful();
    }

    /**
     * Both feeds, faked. The Scorecard payload mirrors the real one: flat dotted
     * keys, ONE `latest.cost.tuition.out_of_state` for the whole school and two
     * unrelated courses under it, which is the entire reason the basis exists.
     */
    private function fakeFeeds(): void
    {
        Http::fake([
            'api.data.gov/*' => Http::response(['results' => [[
                'id' => 104151,
                'school.name' => 'Arizona State University',
                'school.city' => 'Tempe',
                'school.state' => 'AZ',
                'school.school_url' => 'asu.edu',
                'latest.cost.tuition.out_of_state' => 33139,
                'latest.student.size' => 65174,
                'latest.admissions.admission_rate.overall' => 0.8846,
                'latest.completion.completion_rate_4yr_150nt' => 0.6673,
                'latest.earnings.10_yrs_after_entry.median' => 58900,
                'latest.programs.cip_4_digit' => [
                    ['code' => '1101', 'title' => 'COMPUTER SCIENCE', 'credential' => ['level' => 3]],
                    ['code' => '5201', 'title' => 'BUSINESS ADMINISTRATION', 'credential' => ['level' => 5]],
                ],
            ]]]),
            'www2.daad.de/*' => Http::response(['courses' => [[
                'id' => 7788,
                'courseName' => 'MSc Robotics, Cognition, Intelligence',
                'academy' => 'Technical University of Munich',
                'city' => 'Munich',
                'degree' => 'Master of Science',
                'subject' => 'Engineering',
                'programmeDuration' => '4 semesters (24 months)',
                'tuitionFees' => 'None',
            ]]]),
        ]);
    }

    private function partner(): self
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh']);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]));

        return $this->actingAs($user->fresh())->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agency->id]);
    }
}
