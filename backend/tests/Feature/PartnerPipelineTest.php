<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PipelineService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:PartnerAgency,1:User} */
    private function agencyOwner(string $name): array
    {
        $agency = PartnerAgency::create(['legal_name' => $name, 'country' => 'Bangladesh']);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]));

        return [$agency, $user->fresh()];
    }

    private function asPartner(User $user, int $agencyId): self
    {
        return $this->actingAs($user)->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agencyId]);
    }

    private function student(int $agencyId, string $email): Student
    {
        return Student::create(['agency_id' => $agencyId, 'source' => 'partner_modal', 'email' => $email, 'first_name' => 'Test', 'last_name' => 'Lead', 'student_ref' => 'R'.uniqid()]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_create_application_writes_exactly_one_event(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $s = $this->student($agency->id, 's@x.test');

        $this->asPartner($user, $agency->id)->postJson('/api/partner/applications', [
            'student_id' => $s->id, 'intake_month' => 'September', 'intake_year' => 2026,
        ])->assertStatus(201)->assertJsonPath('application.status', 'submitted');

        app(TenantContext::class)->setAgencyId($agency->id);
        $app = Application::firstOrFail();
        $this->assertSame(1, ApplicationStatusEvent::where('application_id', $app->id)->count());
        $ev = ApplicationStatusEvent::first();
        $this->assertNull($ev->from_status);
        $this->assertSame('submitted', $ev->to_status);
    }

    public function test_cannot_create_application_for_another_tenants_student(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB] = $this->agencyOwner('B');
        $bStudent = $this->student($agencyB->id, 'b@x.test');

        // A tries to attach B's student id — refused.
        $this->asPartner($userA, $agencyA->id)->postJson('/api/partner/applications', ['student_id' => $bStudent->id])
            ->assertStatus(404);
    }

    public function test_transition_writes_one_event_and_moves_the_kpi_counter(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        app(TenantContext::class)->setAgencyId($agency->id);
        $s = $this->student($agency->id, 's@x.test');
        $pipeline = app(PipelineService::class);
        $app = $pipeline->create($s, [], $user->id);

        // KPIs: one submitted
        $this->asPartner($user, $agency->id)->getJson('/api/partner/dashboard/kpis')
            ->assertJsonPath('counts.submitted', 1)->assertJsonPath('counts.review', 0)->assertJsonPath('total', 1);

        // transition → review: exactly one new event, counter moves
        $pipeline->transition($app, ApplicationStatus::Review, ActorType::Staff, $user->id, 'Moved to review');
        $this->assertSame(2, ApplicationStatusEvent::where('application_id', $app->id)->count());

        $this->asPartner($user, $agency->id)->getJson('/api/partner/dashboard/kpis')
            ->assertJsonPath('counts.submitted', 0)->assertJsonPath('counts.review', 1)->assertJsonPath('total', 1);
    }

    public function test_kpis_are_tenant_scoped(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        $pipeline = app(PipelineService::class);

        app(TenantContext::class)->setAgencyId($agencyA->id);
        $pipeline->create($this->student($agencyA->id, 'a1@x.test'), [], $userA->id);
        $pipeline->create($this->student($agencyA->id, 'a2@x.test'), [], $userA->id);
        app(TenantContext::class)->setAgencyId($agencyB->id);
        $pipeline->create($this->student($agencyB->id, 'b1@x.test'), [], $userB->id);

        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/dashboard/kpis')->assertJsonPath('total', 2);
        $this->asPartner($userB, $agencyB->id)->getJson('/api/partner/dashboard/kpis')->assertJsonPath('total', 1);
    }

    public function test_deadline_buckets_are_computed_server_side(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        app(TenantContext::class)->setAgencyId($agency->id);
        $pipeline = app(PipelineService::class);
        $pipeline->create($this->student($agency->id, 'd1@x.test'), ['deadline_at' => today()->toDateString()], $user->id);
        $pipeline->create($this->student($agency->id, 'd2@x.test'), ['deadline_at' => today()->addDays(5)->toDateString()], $user->id);
        $pipeline->create($this->student($agency->id, 'd3@x.test'), ['deadline_at' => today()->addDays(20)->toDateString()], $user->id);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/dashboard/deadlines')
            ->assertJsonPath('today', 1)
            ->assertJsonPath('in_7_days', 2)     // today + day5
            ->assertJsonPath('in_14_days', 2);   // day20 excluded
    }
}
