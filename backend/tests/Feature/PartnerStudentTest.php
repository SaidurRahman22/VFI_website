<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Enums\StudentSource;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerStudentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:PartnerAgency,1:User} */
    private function agencyOwner(string $name): array
    {
        $agency = PartnerAgency::create(['legal_name' => $name, 'country' => 'Bangladesh']);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]);

        return [$agency, $user->fresh()];
    }

    private function asPartner(User $user, int $agencyId): self
    {
        return $this->actingAs($user)->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agencyId]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'first_name' => 'Rafi', 'last_name' => 'Ahmed', 'dial' => '+880', 'mobile' => '1712345678',
            'email' => 'rafi@lead.test', 'destination_country' => 'United Kingdom', 'intake_month' => 'September', 'intake_year' => 2026,
        ], $over);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_create_student_owned_by_session_agency(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');

        $this->asPartner($user, $agency->id)->postJson('/api/partner/students', $this->payload())
            ->assertStatus(201)->assertJsonPath('student.name', 'Rafi Ahmed');

        $s = Student::where('email', 'rafi@lead.test')->firstOrFail();
        $this->assertSame($agency->id, $s->agency_id);           // from session
        $this->assertSame(StudentSource::PartnerModal, $s->source);
        $this->assertSame($user->id, $s->registered_by_user_id);
        $this->assertMatchesRegularExpression('/^VFI-\d{4}-\d{5}$/', $s->student_ref);
    }

    public function test_agency_id_in_body_is_ignored_tenant_from_session(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB] = $this->agencyOwner('B');

        // A forges agency_id=B in the body — it must be ignored.
        $this->asPartner($userA, $agencyA->id)
            ->postJson('/api/partner/students', $this->payload(['agency_id' => $agencyB->id]))
            ->assertStatus(201);

        $this->assertSame($agencyA->id, Student::where('email', 'rafi@lead.test')->value('agency_id'));
    }

    public function test_collision_refuses_email_owned_by_another_agency(): void
    {
        [$agencyA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'taken@lead.test', 'first_name' => 'X', 'student_ref' => 'RX']);

        $this->asPartner($userB, $agencyB->id)
            ->postJson('/api/partner/students', $this->payload(['email' => 'taken@lead.test']))
            ->assertStatus(409);
    }

    public function test_collision_refuses_a_self_signup_email_keep_separate(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        // a self-signup portal student (unowned)
        $u = User::factory()->create(['email' => 'selfsignup@lead.test']);
        Student::resolveFor($u);

        $this->asPartner($user, $agency->id)
            ->postJson('/api/partner/students', $this->payload(['email' => 'selfsignup@lead.test']))
            ->assertStatus(409);   // manual modal never claims a self-signup
    }

    public function test_list_is_tenant_scoped_and_filters(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'a1@x.test', 'first_name' => 'Alpha', 'destination_country' => 'Canada', 'student_ref' => 'RA1']);
        Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'a2@x.test', 'first_name' => 'Beta', 'destination_country' => 'United Kingdom', 'student_ref' => 'RA2']);
        Student::create(['agency_id' => $agencyB->id, 'source' => 'partner_modal', 'email' => 'b1@x.test', 'first_name' => 'Gamma', 'student_ref' => 'RB1']);

        // A sees only its two
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 2);
        // keyword filter
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/students?q=Alpha')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Alpha');
        // country filter
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/students?country=Canada')
            ->assertStatus(200)->assertJsonPath('meta.total', 1);
        // B sees only its one — never A's
        $this->asPartner($userB, $agencyB->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'b1@x.test');
    }

    public function test_archived_list_is_separate(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'live@x.test', 'first_name' => 'Live', 'student_ref' => 'RL']);
        Student::create(['agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => 'old@x.test', 'first_name' => 'Old', 'archived_at' => now(), 'student_ref' => 'RO']);

        $this->asPartner($user, $agency->id)->getJson('/api/partner/students')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'live@x.test');
        $this->asPartner($user, $agency->id)->getJson('/api/partner/students?archived=1')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'old@x.test');
    }
}
