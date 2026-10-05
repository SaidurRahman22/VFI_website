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

    /*
     * ------------------------------------------------------------------
     * The discipline filter.
     *
     * partner-search.html has offered a Discipline select, filled from the
     * `discipline_area` taxonomy, since Phase 8 — and search() did not accept
     * the parameter at all, so picking an option changed nothing. These pin
     * both halves of making it real: the parameter is accepted, AND it matches
     * what the catalogue actually stores, which is not the taxonomy's slug.
     *
     * With the test config (2 unis x 3 programmes x 5 countries) the seed emits
     * exactly three disciplines, ten programmes each: Finance, Mechanical
     * Engineering, Software Engineering. Anything else must come back empty.
     * ------------------------------------------------------------------
     */

    /**
     * A TAXONOMY SLUG filters, even though no row contains that slug.
     *
     * This is the whole point of the feature. The ingest never allow-lists
     * discipline_area, so the column holds the feed's own wording — the seed
     * writes "Finance", Scorecard writes "Finance And Financial Management
     * Services." — while the dropdown posts `finance`. A plain equality match
     * would return nothing for all 18 options and the control would still be
     * decorative, just with a backend behind it.
     */
    public function test_a_taxonomy_discipline_slug_matches_the_catalogue_wording(): void
    {
        $res = $this->partner()->getJson('/api/partner/programs/search?discipline_area=finance&per_page=50')
            ->assertStatus(200);

        $this->assertSame(10, $res->json('meta.total'));
        foreach ($res->json('data') as $row) {
            $this->assertSame('Finance', $row['discipline_area']);
        }
    }

    /**
     * The taxonomy LABEL works as well as the value.
     *
     * /api/taxonomy serves both halves of every term and a <select> can
     * reasonably post either; from this side they are indistinguishable
     * strings. Accepting only one of them would leave the filter alive or dead
     * depending on a line of JavaScript nobody would think to check.
     */
    public function test_the_taxonomy_label_filters_as_well_as_the_value(): void
    {
        $this->partner()->getJson('/api/partner/programs/search?discipline_area='.rawurlencode('Finance & Accounting').'&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 10);

        // case-folded, because a label is prose and nobody should have to
        // reproduce its capitalisation
        $this->partner()->getJson('/api/partner/programs/search?discipline_area='.rawurlencode('finance & accounting').'&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 10);
    }

    /** The extra terms earn their place: `software` reaches "Software Engineering". */
    /**
     * A discipline the catalogue does not classify is still reachable.
     *
     * Measured on live: 17 of 18 taxonomy disciplines reach rows through
     * discipline_area, and Cybersecurity reaches none — not because the
     * programmes are absent but because every one of them is filed under
     * discipline_area "Computer Science", with the distinction living only in
     * the title. A dropdown option that returns nothing while the programmes
     * plainly exist is the fake control this whole pass is about.
     */
    public function test_cybersecurity_is_found_by_title_when_the_discipline_column_hides_it(): void
    {
        $row = DB::table('program_search')->where('is_stale', false)->first();
        DB::table('program_search')->where('id', $row->id)->update([
            'title' => 'Cyber Security (MSc)',
            'discipline_area' => 'Computer Science',   // what the feed really files it as
        ]);

        $res = $this->partner()->getJson('/api/partner/programs/search?discipline_area=cybersecurity&per_page=50')
            ->assertStatus(200);

        $this->assertSame(1, $res->json('meta.total'), 'the title is where the distinction lives');
        $this->assertSame((int) $row->program_id, $res->json('data.0.program_id'));
    }

    /**
     * And the title fallback must not leak to the other disciplines, or a
     * classification filter quietly becomes a keyword search.
     */
    public function test_the_title_fallback_does_not_widen_other_disciplines(): void
    {
        $row = DB::table('program_search')->where('is_stale', false)->first();
        DB::table('program_search')->where('id', $row->id)->update([
            'title' => 'Business Law and Society (LLB)',
            'discipline_area' => 'Business Administration',
        ]);

        $res = $this->partner()->getJson('/api/partner/programs/search?discipline_area=law&per_page=50')
            ->assertStatus(200);

        foreach ($res->json('data') as $card) {
            $this->assertNotSame(
                (int) $row->program_id,
                $card['program_id'],
                'a law-titled business programme must not answer the Law discipline filter'
            );
        }
    }

    public function test_each_seeded_discipline_is_reachable_from_its_slug(): void
    {
        foreach (['mechanical' => 'Mechanical Engineering', 'software' => 'Software Engineering'] as $slug => $wording) {
            $res = $this->partner()->getJson('/api/partner/programs/search?discipline_area='.$slug.'&per_page=50')
                ->assertStatus(200);

            $this->assertSame(10, $res->json('meta.total'), "slug {$slug} must reach {$wording}");
            $this->assertSame($wording, $res->json('data.0.discipline_area'));
        }
    }

    /**
     * The filter is APPLIED, not quietly dropped.
     *
     * A valid taxonomy slug with nothing behind it must return an empty page.
     * If this ever came back with 30 programmes it would mean the parameter had
     * been accepted by validate() and then ignored — which is exactly the
     * failure this feature exists to fix, dressed up as a working filter.
     */
    public function test_a_discipline_with_no_programmes_returns_an_empty_page(): void
    {
        $this->partner()->getJson('/api/partner/programs/search?discipline_area=nursing&per_page=50')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    /**
     * A value that is NOT in the taxonomy is matched exactly, case-folded.
     *
     * That is what a caller echoing a card's own `discipline_area` back at us
     * means, and it must not be widened into a substring search — "Engineering"
     * is a substring of two of the three seeded disciplines and is still not a
     * request for either of them.
     */
    public function test_a_literal_catalogue_value_matches_exactly_and_ignores_case(): void
    {
        $this->partner()->getJson('/api/partner/programs/search?discipline_area='.rawurlencode('mechanical engineering').'&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 10);

        $this->partner()->getJson('/api/partner/programs/search?discipline_area='.rawurlencode('Engineering').'&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 0);

        $this->partner()->getJson('/api/partner/programs/search?discipline_area='.rawurlencode('Basket Weaving').'&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 0);
    }

    /** It narrows alongside the other filters rather than replacing them. */
    public function test_discipline_combines_with_the_other_filters(): void
    {
        // UK: 2 unis x 1 finance programme each
        $this->partner()->getJson('/api/partner/programs/search?country=United+Kingdom&discipline_area=finance&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 2);

        // and a combination with nothing in it is empty, not the union
        $this->partner()->getJson('/api/partner/programs/search?country=United+Kingdom&discipline_area=nursing&per_page=50')
            ->assertStatus(200)->assertJsonPath('meta.total', 0);
    }

    /**
     * Longer than the column it filters is a bad request, not a slow way of
     * finding nothing. 90 is program_search.discipline_area's own width.
     */
    public function test_an_over_long_discipline_is_rejected(): void
    {
        $this->partner()->getJson('/api/partner/programs/search?discipline_area='.str_repeat('a', 91))
            ->assertStatus(422)->assertJsonValidationErrors('discipline_area');
    }
}
