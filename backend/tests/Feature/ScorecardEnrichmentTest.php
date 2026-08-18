<?php

namespace Tests\Feature;

use App\Models\Catalogue\Institution;
use App\Models\Catalogue\Program;
use App\Services\Ingest\CollegeScorecardSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The client's requirement was "real cost, real admission requirements, real
 * living cost, real campus information" — and on live those four sections of the
 * university detail page were rendering EMPTY for all 387 universities while the
 * published figures sat one API field away.
 *
 * This proves the mapping against a payload shaped exactly like the College
 * Scorecard's, because the live run cannot prove it: the DEMO key allows roughly
 * 30 requests an hour, so the real ingest is parked on a resume point and fills
 * in over several hours. A test that waits on someone's quota is not a test.
 *
 * Every value asserted here is public-domain, published by the U.S. Department
 * of Education. Nothing in the mapping invents a number.
 */
class ScorecardEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('ingest:scorecard:next_page');
        config([
            'catalogue.scorecard.key' => 'test-key',
            'catalogue.scorecard.per_page' => 1,
            'catalogue.scorecard.max_institutions' => 1,
        ]);
    }

    /** One school, shaped as the API returns it (flat dotted keys). */
    private function fakeSchool(array $overrides = []): void
    {
        Http::fake(['api.data.gov/*' => Http::response([
            'results' => [array_merge([
                'id' => 999001,
                'school.name' => 'Example State University',
                'school.city' => 'Tempe',
                'school.state' => 'AZ',
                'school.school_url' => 'www.example.edu',
                'school.locale' => 12,        // 11/12/13 = city
                'school.ownership' => 1,      // 1 = public
                'latest.student.size' => 41234,
                'latest.admissions.admission_rate.overall' => 0.86,
                'latest.completion.completion_rate_4yr_150nt' => 0.63,
                'latest.earnings.10_yrs_after_entry.median' => 58000,
                'latest.cost.tuition.in_state' => 11618,
                'latest.cost.tuition.out_of_state' => 33139,
                'latest.cost.booksupply' => 1160,
                'latest.cost.roomboard.oncampus' => 14116,
                'latest.cost.roomboard.offcampus' => 13500,
                'latest.cost.attendance.academic_year' => 32000,
                'latest.cost.avg_net_price.public' => 15274,
                'latest.admissions.sat_scores.25th_percentile.critical_reading' => 550,
                'latest.admissions.sat_scores.75th_percentile.critical_reading' => 660,
                'latest.admissions.sat_scores.25th_percentile.math' => 540,
                'latest.admissions.sat_scores.75th_percentile.math' => 680,
                'latest.admissions.act_scores.25th_percentile.cumulative' => 22,
                'latest.admissions.act_scores.75th_percentile.cumulative' => 28,
                'latest.programs.cip_4_digit' => [
                    ['code' => '1107', 'title' => 'Computer Science', 'credential' => ['level' => 3]],
                ],
            ], $overrides)],
        ], 200)]);
    }

    private function ingest(): Institution
    {
        $this->artisan('programs:ingest', ['--source' => 'scorecard', '--no-index' => true])
            ->assertSuccessful();

        return Institution::where('source', 'scorecard')->firstOrFail();
    }

    public function test_the_cost_table_is_built_from_published_figures(): void
    {
        $this->fakeSchool();
        $rows = collect($this->ingest()->cost_rows_json)->pluck('value', 'label');

        $this->assertSame('USD 11,618', $rows['Tuition (in-state)']);
        $this->assertSame('USD 33,139', $rows['Tuition (out-of-state / international)']);
        $this->assertSame('USD 1,160', $rows['Books and supplies']);
        $this->assertSame('USD 14,116', $rows['Room and board (on campus)']);
        $this->assertSame('USD 32,000', $rows['Total cost of attendance']);
        // The net price is the more honest headline: what students actually pay.
        $this->assertSame('USD 15,274', $rows['Average net price paid (after aid)']);
    }

    public function test_the_cost_note_names_its_source_and_its_limitation(): void
    {
        $this->fakeSchool();
        $note = $this->ingest()->cost_note;

        $this->assertStringContainsString('U.S. Department of Education', $note);
        // The caveat is the point: these are institution-wide averages, and a
        // counsellor must not quote one as a specific programme's fee.
        $this->assertStringContainsString('institution-wide averages', $note);
    }

    public function test_living_cost_is_the_real_room_and_board_figure(): void
    {
        $this->fakeSchool();
        $this->assertSame('USD 14,116 per year (room and board)', $this->ingest()->living_cost_note);
    }

    public function test_admission_requirements_are_the_published_test_ranges(): void
    {
        $this->fakeSchool();
        $adm = $this->ingest()->admission_academic;

        // SAT is reported per section; the range a student recognises is the sum.
        $this->assertStringContainsString('SAT 1090', $adm);
        $this->assertStringContainsString('1340', $adm);
        $this->assertStringContainsString('ACT 22', $adm);
        $this->assertStringContainsString('28', $adm);
    }

    public function test_english_requirements_are_left_empty_rather_than_invented(): void
    {
        $this->fakeSchool();

        // The Scorecard publishes no IELTS/TOEFL requirement. A blank field is
        // honest; a plausible-looking band score is what a counsellor would quote
        // to a student and be wrong about.
        $this->assertNull($this->ingest()->admission_english);
    }

    public function test_the_overview_is_assembled_only_from_feed_values(): void
    {
        $this->fakeSchool();
        $overview = $this->ingest()->overview;

        $this->assertStringContainsString('Example State University', $overview);
        $this->assertStringContainsString('public', $overview);        // ownership = 1
        $this->assertStringContainsString('in a city', $overview);     // locale = 12
        $this->assertStringContainsString('Tempe, AZ', $overview);
        $this->assertStringContainsString('41,234 students', $overview);
        $this->assertStringContainsString('College Scorecard', $overview);
    }

    public function test_a_school_reporting_no_cost_gets_empty_fields_not_zeroes(): void
    {
        $this->fakeSchool([
            'latest.cost.tuition.in_state' => null,
            'latest.cost.tuition.out_of_state' => null,
            'latest.cost.booksupply' => 0,
            'latest.cost.roomboard.oncampus' => null,
            'latest.cost.roomboard.offcampus' => null,
            'latest.cost.attendance.academic_year' => null,
            'latest.cost.avg_net_price.public' => null,
            'latest.admissions.sat_scores.25th_percentile.critical_reading' => null,
            'latest.admissions.sat_scores.75th_percentile.critical_reading' => null,
            'latest.admissions.sat_scores.25th_percentile.math' => null,
            'latest.admissions.sat_scores.75th_percentile.math' => null,
            'latest.admissions.act_scores.25th_percentile.cumulative' => null,
            'latest.admissions.act_scores.75th_percentile.cumulative' => null,
        ]);
        $inst = $this->ingest();

        // "USD 0" would be a lie the page would render as fact.
        $this->assertNull($inst->cost_rows_json);
        $this->assertNull($inst->cost_note);
        $this->assertNull($inst->living_cost_note);
        $this->assertNull($inst->admission_academic);
    }

    public function test_a_staff_edit_survives_the_next_feed_run(): void
    {
        $this->fakeSchool();
        $inst = $this->ingest();
        $this->assertNotNull($inst->cost_rows_json);          // the feed filled it

        $inst->forceFill([
            'cost_note' => 'Confirmed with the admissions office.',
            'living_cost_note' => 'USD 1,200 per month',
            'admission_academic' => 'GPA 3.0 minimum',
        ])->save();

        Cache::forget('ingest:scorecard:next_page');
        $this->fakeSchool();
        $this->artisan('programs:ingest', ['--source' => 'scorecard', '--no-index' => true])->assertSuccessful();

        $inst->refresh();
        $this->assertSame('Confirmed with the admissions office.', $inst->cost_note);
        $this->assertSame('USD 1,200 per month', $inst->living_cost_note);
        $this->assertSame('GPA 3.0 minimum', $inst->admission_academic);
    }

    /** @depends test_the_cost_table_is_built_from_published_figures */
    public function test_the_source_still_reports_itself_as_an_institution_average(): void
    {
        $this->fakeSchool();
        $this->ingest();

        // The per-programme tuition remains the school-wide figure, so the basis
        // must keep saying so — the cost table does not change that.
        $this->assertSame('institution_average',
            Program::where('source', 'scorecard')->firstOrFail()->tuition_basis);
    }

    public function test_the_source_name_is_unchanged(): void
    {
        $this->assertSame('scorecard', (new CollegeScorecardSource)->name());
    }
}
