<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerTenancyGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:PartnerAgency,1:User} a fresh agency + its owner user */
    private function agencyWithOwner(string $name, string $country, string $person): array
    {
        $agency = PartnerAgency::create(['legal_name' => $name, 'country' => $country]);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner,
            'contact_person_name' => $person, 'work_email' => strtolower($person).'@x.test', 'status' => MemberStatus::Active,
        ]);

        return [$agency, $user->fresh()];
    }

    private function asPartner(User $user, int $agencyId): self
    {
        // Simulates what P6-E sign-in will set — the tenant comes from the session.
        return $this->actingAs($user)->withSession([
            'active_scope' => 'partner',
            'active_partner_agency_id' => $agencyId,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_unauthenticated_and_wrong_scope_are_refused(): void
    {
        $this->getJson('/api/partner/me')->assertStatus(401);

        // a student session must not reach partner routes
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);
        $this->actingAs($u->fresh())->withSession(['active_scope' => 'student'])
            ->getJson('/api/partner/me')->assertStatus(401);
    }

    public function test_greeting_resolves_per_authenticated_agency(): void
    {
        [$a, $ua] = $this->agencyWithOwner('Acme Study Abroad', 'Bangladesh', 'Alice');

        $this->asPartner($ua, $a->id)->getJson('/api/partner/me')->assertStatus(200)
            ->assertJsonPath('agency.name', 'Acme Study Abroad')
            ->assertJsonPath('member.name', 'Alice')
            ->assertJsonPath('member.initial', 'A')
            ->assertJsonPath('member.seat_role', 'owner');
    }

    public function test_cross_tenant_read_is_impossible_even_with_a_forged_id(): void
    {
        [$a, $ua] = $this->agencyWithOwner('Agency A', 'Bangladesh', 'Alice');
        [$b, $ub] = $this->agencyWithOwner('Agency B', 'India', 'Bob');

        // Agency A sees only its own seat…
        $resA = $this->asPartner($ua, $a->id)->getJson('/api/partner/members?agency_id='.$b->id)->assertStatus(200);
        $resA->assertJsonPath('count', 1);
        $this->assertSame([$ua->id], collect($resA->json('members'))->pluck('user_id')->all());
        // …the forged ?agency_id=B was ignored; the session tenant wins
        $resA->assertJsonPath('agency_id', $a->id);

        // Agency B independently sees only its own seat
        $resB = $this->asPartner($ub, $b->id)->getJson('/api/partner/members')->assertStatus(200);
        $resB->assertJsonPath('count', 1);
        $this->assertSame([$ub->id], collect($resB->json('members'))->pluck('user_id')->all());
    }

    public function test_a_partner_bound_to_a_foreign_session_still_only_sees_that_tenant(): void
    {
        // Even if a session claims agency B, the reads are B's — never a merge.
        [$a, $ua] = $this->agencyWithOwner('Agency A', 'Bangladesh', 'Alice');
        [$b] = $this->agencyWithOwner('Agency B', 'India', 'Bob');

        // ua carries a role for A, but the session is (contrived) bound to B →
        // reads are B's rows, and ua is not among them (no leak of A into B).
        $res = $this->asPartner($ua, $b->id)->getJson('/api/partner/members')->assertStatus(200);
        $res->assertJsonPath('count', 1);
        $this->assertNotContains($ua->id, collect($res->json('members'))->pluck('user_id')->all());
    }
}
