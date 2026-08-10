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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]);

        return $this->actingAs($user->fresh())->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agency->id]);
    }

    public function test_search_requires_partner_auth(): void
    {
        $this->getJson('/api/partner/programs/search')->assertStatus(401);
    }

    public function test_default_search_hides_stale_intakes(): void
    {
        // 5 countries × 2 unis × 3 programs × 3 intakes = 90; base_year Fall is past → 30 stale
        $res = $this->partner()->getJson('/api/partner/programs/search')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 60);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->partner()->getJson('/api/partner/programs/search?include_stale=1')
            ->assertStatus(200)->assertJsonPath('meta.total', 90);
    }

    public function test_country_filter_narrows_results(): void
    {
        // UK: 18 rows total, 6 stale → 12 fresh
        $res = $this->partner()->getJson('/api/partner/programs/search?country=United+Kingdom')->assertStatus(200);
        $this->assertSame(12, $res->json('meta.total'));
        foreach ($res->json('data') as $row) {
            $this->assertSame('United Kingdom', $row['country']);
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
}
