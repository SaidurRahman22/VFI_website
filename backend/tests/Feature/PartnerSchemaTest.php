<?php

namespace Tests\Feature;

use App\Enums\AgencyStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\PartnerApplication;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_enums_behaviour(): void
    {
        $this->assertTrue(AgencyStatus::Approved->canOperate());
        $this->assertFalse(AgencyStatus::PendingReview->canOperate());
        $this->assertFalse(AgencyStatus::Suspended->canOperate());
        $this->assertSame(Role::PartnerOwner, SeatRole::Owner->role());
        $this->assertSame(Role::PartnerCounsellor, SeatRole::Counsellor->role());
    }

    public function test_agency_and_application_relations(): void
    {
        $user = User::factory()->create();
        $agency = PartnerAgency::create(['legal_name' => 'Acme Study Abroad', 'country' => 'Bangladesh', 'city' => 'Dhaka']);
        $app = PartnerApplication::create([
            'agency_name' => 'Acme Study Abroad', 'country' => 'Bangladesh', 'city' => 'Dhaka',
            'contact_person' => 'Jane', 'work_email' => 'jane@acme.test', 'user_id' => $user->id,
            'authorised_signatory_attested' => true, 'agency_id' => $agency->id,
        ]);

        $this->assertSame(AgencyStatus::PendingReview, $agency->fresh()->status);   // DB default
        $this->assertTrue($app->authorised_signatory_attested);
        $this->assertTrue($app->agency->is($agency));
        $this->assertTrue($app->user->is($user));
    }

    public function test_member_is_tenant_isolated(): void
    {
        $a1 = PartnerAgency::create(['legal_name' => 'A1', 'country' => 'Bangladesh']);
        $a2 = PartnerAgency::create(['legal_name' => 'A2', 'country' => 'India']);
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // create each seat under its own tenant context
        $this->tenant()->setAgencyId($a1->id);
        PartnerAgencyMember::create(['agency_id' => $a1->id, 'user_id' => $u1->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]);
        $this->tenant()->setAgencyId($a2->id);
        PartnerAgencyMember::create(['agency_id' => $a2->id, 'user_id' => $u2->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]);

        // agency 1 context sees only its own seat
        $this->tenant()->setAgencyId($a1->id);
        $this->assertSame(1, PartnerAgencyMember::count());
        $this->assertTrue($this->tenant()->agencyId() === $a1->id);
        $this->assertSame($u1->id, PartnerAgencyMember::first()->user_id);

        // fail-closed with no tenant
        $this->tenant()->clear();
        $this->assertSame(0, PartnerAgencyMember::count());

        // the escape hatch shows both rows exist (app-scope removed; RLS is the
        // Postgres-only second net, exercised in staging)
        $this->assertSame(2, PartnerAgencyMember::withoutGlobalScope(BelongsToAgencyScope::class)->count());
    }

    public function test_seat_role_and_status_cast(): void
    {
        $agency = PartnerAgency::create(['legal_name' => 'Cast Co', 'country' => 'Nepal']);
        $this->tenant()->setAgencyId($agency->id);
        $m = PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => User::factory()->create()->id,
            'seat_role' => SeatRole::Counsellor, 'status' => MemberStatus::Invited,
        ]);

        $this->assertInstanceOf(SeatRole::class, $m->refresh()->seat_role);
        $this->assertSame(SeatRole::Counsellor, $m->seat_role);
        $this->assertFalse($m->status->canOperate());
    }
}
