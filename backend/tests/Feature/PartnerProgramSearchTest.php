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
use Tests\TestCase;

class PartnerProgramSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-15');
        config([
            'catalogue.seed.universities_per_country' => 2,
            'catalogue.seed.programs_per_university' => 3,
            'catalogue.seed.base_year' => 2026,
        ]);
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
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

    public function test_search_requires_partner_auth(): void
    {
        $this->getJson('/api/partner/programs/search')->assertStatus(401);
    }

    /**
     * A stale intake disappears from its programme's card; the programme does
     * not disappear from the list.
     *
     * The counts moved when the list collapsed to one card per programme, and
     * the move is the point: 5 countries x 2 unis x 3 programs = 30 programmes,
     * each with 3 intakes = 90 rows. The old assertion of 60 was counting
     * fresh ROWS and calling them results. What a partner actually needs to
     * know is that the programme is still offered and that the past date is no
     * longer on it.
     */
    public function test_a_stale_intake_leaves_the_card_but_not_the_programme(): void
    {
        $res = $this->partner()->getJson('/api/partner/programs/search?per_page=50')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 30);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        // base_year Fall is past, so each programme shows its other two intakes
        foreach ($res->json('data') as $row) {
            $this->assertCount(2, $row['intakes'], 'the past intake must not be offered');
            foreach ($row['intakes'] as $intake) {
                $this->assertFalse($intake['is_stale']);
            }
        }

        // asking for them back returns the third, flagged
        $all = $this->partner()->getJson('/api/partner/programs/search?include_stale=1&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 30);
        foreach ($all->json('data') as $row) {
            $this->assertCount(3, $row['intakes']);
            $this->assertSame(1, collect($row['intakes'])->where('is_stale', true)->count());
        }
    }

    /** The same programme must never appear twice, whatever it is filtered by. */
    public function test_a_programme_appears_exactly_once(): void
    {
        $data = $this->partner()->getJson('/api/partner/programs/search?include_stale=1&per_page=50')
            ->assertStatus(200)->json('data');

        $ids = array_column($data, 'program_id');
        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'one programme with three intakes was rendering as three cards'
        );
    }

    public function test_country_filter_narrows_results(): void
    {
        // UK: 2 unis x 3 programs = 6 programmes (18 rows, of which 6 are stale)
        $res = $this->partner()->getJson('/api/partner/programs/search?country=United+Kingdom')->assertStatus(200);
        $this->assertSame(6, $res->json('meta.total'));
        foreach ($res->json('data') as $row) {
            $this->assertSame('United Kingdom', $row['country']);
        }
    }

    /**
     * Filtering by intake must narrow what the CARD offers, not just which
     * cards appear. A search for Spring that returns a card listing Fall and
     * Summer as well has answered a question nobody asked.
     */
    public function test_an_intake_filter_narrows_the_intakes_on_the_card(): void
    {
        $data = $this->partner()->getJson('/api/partner/programs/search?intake=spring&per_page=50')
            ->assertStatus(200)->json('data');

        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertCount(1, $row['intakes']);
            $this->assertSame('spring', $row['intakes'][0]['season']);
        }
    }

    public function test_stem_facet_returns_only_stem_rows(): void
    {
        $res = $this->partner()->getJson('/api/partner/programs/search?include_stale=1&facets[]=stem')->assertStatus(200);
        $this->assertGreaterThan(0, $res->json('meta.total'));
        $this->assertLessThan(90, $res->json('meta.total'));
        foreach ($res->json('data') as $row) {
            $this->assertContains('stem', $row['badges']);
        }
    }

    public function test_facet_must_be_in_the_allow_list(): void
    {
        $this->partner()->getJson('/api/partner/programs/search?facets[]=hackme')->assertStatus(422);
    }

    public function test_levels_array_filter(): void
    {
        $res = $this->partner()->getJson('/api/partner/programs/search?include_stale=1&levels[]=master&levels[]=bachelor')
            ->assertStatus(200);
        $this->assertGreaterThan(0, $res->json('meta.total'));
        foreach ($res->json('data') as $row) {
            $this->assertContains($row['level'], ['master', 'bachelor']);
        }
    }

    public function test_high_job_demand_facet(): void
    {
        $res = $this->partner()->getJson('/api/partner/programs/search?include_stale=1&facets[]=high_job_demand')
            ->assertStatus(200);
        $this->assertGreaterThan(0, $res->json('meta.total'));
        $this->assertLessThan(90, $res->json('meta.total'));
        foreach ($res->json('data') as $row) {
            $this->assertContains('high_job_demand', $row['badges']);
        }
    }

    public function test_tuition_max_excludes_dearer_and_null_tuition(): void
    {
        $res = $this->partner()->getJson('/api/partner/programs/search?include_stale=1&tuition_max=1800000')->assertStatus(200);
        foreach ($res->json('data') as $row) {
            $this->assertNotNull($row['tuition']);
            $this->assertLessThanOrEqual(1800000, $row['tuition']['minor']);
        }
    }

    public function test_sort_options_are_accepted(): void
    {
        foreach (['deadline', 'tuition_asc', 'tuition_desc', 'fastest_offer', 'newest'] as $sort) {
            $this->partner()->getJson("/api/partner/programs/search?sort={$sort}")->assertStatus(200);
        }
    }

    public function test_detail_returns_full_program(): void
    {
        $program = Program::query()->firstOrFail();

        $this->partner()->getJson("/api/partner/programs/{$program->id}")
            ->assertStatus(200)
            ->assertJsonPath('program.id', $program->id)
            ->assertJsonPath('program.institution.country', fn ($c) => is_string($c))
            ->assertJsonCount(3, 'program.intakes')
            ->assertJsonPath('program.requirements.0.test', fn ($t) => is_string($t));
    }

    public function test_detail_404_for_unknown_program(): void
    {
        $this->partner()->getJson('/api/partner/programs/99999')->assertStatus(404);
    }

    /**
     * Live regression: the seed feed only covers the destinations with no
     * licensed source, and its fabricated rows carry the nearest deadlines. With
     * the default deadline-ascending sort that put all 240 of them ahead of
     * 41,000 real programmes — every card on page one of Search read
     * "Sample data". Source ranking has to beat the chosen sort, so a real row
     * with the LATEST possible deadline must still lead.
     */
    public function test_sample_rows_never_lead_the_real_catalogue(): void
    {
        $promoted = DB::table('program_search')->where('is_stale', false)->first();
        DB::table('program_search')->where('id', $promoted->id)->update([
            'source' => 'scorecard',
            'application_deadline_at' => '2099-12-31',
        ]);

        $data = $this->partner()->getJson('/api/partner/programs/search')->assertStatus(200)->json('data');

        $this->assertSame('scorecard', $data[0]['source'], 'a real programme must lead the sample ones');
        $this->assertSame((int) $promoted->program_id, $data[0]['program_id']);

        // and everything behind it is still ordered by the requested sort
        $this->assertSame('seed', $data[1]['source']);
    }

    /**
     * meta.total and meta.programs now agree, because a result IS a programme.
     *
     * They used to differ on purpose: a row was one programme INTAKE, and
     * reporting that count as "programmes" is what told the partner the
     * catalogue held 123,621 when it holds 41,287. That was a patch over the
     * real defect - the list itself was exploded - and with the list collapsed
     * the two numbers describe the same thing. meta.programs is kept so the
     * page reading it does not break.
     */
    public function test_meta_counts_programmes_not_intake_rows(): void
    {
        // 5 countries x 2 unis x 3 programs = 30 programmes, 3 intakes each = 90 rows
        $res = $this->partner()->getJson('/api/partner/programs/search?include_stale=1')->assertStatus(200);

        $this->assertSame(30, $res->json('meta.total'), 'the row count must never be reported as results again');
        $this->assertSame(30, $res->json('meta.programs'));
    }
}
