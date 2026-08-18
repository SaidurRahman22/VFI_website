<?php

namespace Tests\Feature;

use App\Enums\AgencyStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\ContentAuditLog;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AgencySuspensionService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 9A slice 3 — suspend / close / reinstate an agency. The behaviour that
 * matters is that access stops IMMEDIATELY, not at session expiry.
 */
class AgencySuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    /** @return array{0:PartnerAgency,1:User} */
    private function agency(string $status = 'approved'): array
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh', 'status' => $status]);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        app(TenantContext::class)->setAgencyId($agency->id);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => $user->id,
            'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active,
        ]));
        app(TenantContext::class)->clear();

        return [$agency->refresh(), $user];
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    public function test_suspending_flips_status_and_kills_live_sessions(): void
    {
        [$agency, $member] = $this->agency();

        // the member is signed in right now
        DB::table('sessions')->insert([
            'id' => 'sess-'.uniqid(), 'user_id' => $member->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $member->id)->count());

        app(AgencySuspensionService::class)->suspend($agency, $this->staff(), 'Fraudulent applications');

        $this->assertSame(AgencyStatus::Suspended, $agency->refresh()->status);
        $this->assertFalse($agency->status->canOperate());
        // cut off now, not when the session would have expired
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->count());
    }

    public function test_a_reason_is_required(): void
    {
        [$agency] = $this->agency();

        $this->expectException(\RuntimeException::class);
        app(AgencySuspensionService::class)->suspend($agency, $this->staff(), '   ');
    }

    public function test_the_decision_is_audited_with_before_and_after(): void
    {
        [$agency] = $this->agency();
        $staff = $this->staff();

        app(AgencySuspensionService::class)->suspend($agency, $staff, 'Repeated policy breach');

        $audit = ContentAuditLog::where('entity', 'partner_agency')->where('entity_id', (string) $agency->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('agency_status', $audit->action);
        $this->assertSame('approved', $audit->before['status']);
        $this->assertSame('suspended', $audit->after['status']);
        $this->assertSame('Repeated policy breach', $audit->after['reason']);
        $this->assertSame($staff->id, $audit->after['actor_user_id']);
    }

    public function test_a_suspended_agency_can_be_reinstated(): void
    {
        [$agency] = $this->agency('suspended');

        app(AgencySuspensionService::class)->reinstate($agency, $this->staff(), 'Investigation cleared them');

        $this->assertSame(AgencyStatus::Approved, $agency->refresh()->status);
        $this->assertTrue($agency->status->canOperate());
    }

    public function test_a_closed_agency_cannot_be_reinstated(): void
    {
        [$agency] = $this->agency('closed');

        $this->expectException(\RuntimeException::class);
        app(AgencySuspensionService::class)->reinstate($agency, $this->staff(), 'changed our mind');
    }

    public function test_a_no_op_status_change_is_refused(): void
    {
        [$agency] = $this->agency('suspended');

        $this->expectException(\RuntimeException::class);
        app(AgencySuspensionService::class)->suspend($agency, $this->staff(), 'again');
    }

    public function test_a_suspended_agency_cannot_sign_in(): void
    {
        [$agency, $member] = $this->agency();
        $member->forceFill(['password' => 'Str0ng!Passw0rd#9'])->save();

        app(AgencySuspensionService::class)->suspend($agency, $this->staff(), 'Suspended for review');

        $this->postJson('/api/partner/signin', [
            'email' => $member->email, 'password' => 'Str0ng!Passw0rd#9',
        ])->assertStatus(403);   // the review gate, not a credential error
    }
}
