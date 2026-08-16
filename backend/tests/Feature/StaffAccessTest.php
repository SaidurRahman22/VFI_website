<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Partner\PartnerAgency;
use App\Models\StaffAccessLog;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Services\StaffAccessService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9A slice 5 — the audited door through the tenancy boundary. Tenancy is
 * absolute everywhere else, so this door has to be provably narrow.
 */
class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function userWithRole(?Role $role): User
    {
        $u = User::factory()->create();
        if ($role) {
            UserRole::create(['user_id' => $u->id, 'role' => $role->value, 'granted_at' => now()]);
        }

        return $u->fresh();
    }

    private function studentOfSomeAgency(): Student
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh']);
        app(TenantContext::class)->setAgencyId($agency->id);
        $s = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'pupil@acme.test', 'first_name' => 'Pupil', 'student_ref' => 'R-'.uniqid(),
        ]);
        app(TenantContext::class)->clear();

        return $s;
    }

    private function svc(): StaffAccessService
    {
        return app(StaffAccessService::class);
    }

    public function test_an_allowed_role_can_open_a_record_and_it_is_logged(): void
    {
        $staff = $this->userWithRole(Role::StaffCounsellor);
        $student = $this->studentOfSomeAgency();

        $opened = $this->svc()->openStudent($staff, $student->id, 'Complaint ref 4821 — verifying the passport sent to us.');

        $this->assertSame($student->id, $opened->id);

        $log = StaffAccessLog::where('subject_type', 'student')->where('subject_id', $student->id)->firstOrFail();
        $this->assertSame($staff->id, $log->actor_user_id);
        $this->assertSame($staff->email, $log->actor_email);
        $this->assertSame($student->agency_id, $log->subject_agency_id);   // whose tenant was entered
        $this->assertStringContainsString('Complaint ref 4821', $log->reason);
    }

    public function test_a_superadmin_may_also_open_records(): void
    {
        $staff = $this->userWithRole(Role::SuperAdmin);
        $student = $this->studentOfSomeAgency();

        $this->svc()->openStudent($staff, $student->id, 'Data subject access request received today.');

        $this->assertSame(1, StaffAccessLog::where('subject_id', $student->id)->count());
    }

    public function test_a_role_without_cross_tenant_sight_is_refused(): void
    {
        $staff = $this->userWithRole(Role::ContentEditor);
        $student = $this->studentOfSomeAgency();

        $this->expectException(\RuntimeException::class);
        $this->svc()->openStudent($staff, $student->id, 'Just having a look at this record.');
    }

    public function test_a_user_with_no_role_at_all_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->openStudent($this->userWithRole(null), $this->studentOfSomeAgency()->id, 'Curiosity about this one.');
    }

    public function test_a_vague_reason_is_refused_and_nothing_is_logged(): void
    {
        $staff = $this->userWithRole(Role::StaffCounsellor);
        $student = $this->studentOfSomeAgency();

        try {
            $this->svc()->openStudent($staff, $student->id, 'checking');   // under 10 chars
            $this->fail('a vague reason must be refused');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, StaffAccessLog::count());
    }

    public function test_a_refused_read_never_logs_and_never_returns_data(): void
    {
        $staff = $this->userWithRole(Role::ContentEditor);
        $student = $this->studentOfSomeAgency();

        try {
            $this->svc()->openStudent($staff, $student->id, 'A perfectly detailed sounding reason.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, StaffAccessLog::count());
    }

    public function test_the_allowed_roles_can_be_narrowed_by_config(): void
    {
        config(['auth.cross_tenant_roles' => ['superadmin']]);

        $this->assertTrue($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::SuperAdmin)));
        // narrowed: the counsellor is no longer permitted
        $this->assertFalse($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::StaffCounsellor)));
    }

    public function test_a_broken_config_falls_back_to_the_confirmed_pair_not_open_access(): void
    {
        // a typo must never widen the door
        config(['auth.cross_tenant_roles' => ['not_a_real_role', '']]);

        $this->assertTrue($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::SuperAdmin)));
        $this->assertTrue($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::StaffCounsellor)));
        $this->assertFalse($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::ContentEditor)));
    }

    public function test_the_permission_check_is_exposed_for_gating_the_screen(): void
    {
        $this->assertTrue($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::StaffCounsellor)));
        $this->assertTrue($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::SuperAdmin)));
        $this->assertFalse($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::ContentEditor)));
        $this->assertFalse($this->svc()->mayReadAcrossTenants($this->userWithRole(Role::Student)));
    }

    /**
     * Documents how student tenancy actually works today, so a future change is
     * a deliberate one: unlike the other tenant models, Student carries NO
     * fail-closed global scope — isolation depends on each call site using
     * ->forAgency(). The audited door is therefore what supplies the reason and
     * the record of the read, not the reach itself.
     */
    public function test_student_isolation_is_call_site_scoping_not_a_global_scope(): void
    {
        $student = $this->studentOfSomeAgency();

        // no tenant context, yet an unscoped read still returns the row
        $this->assertNotNull(Student::find($student->id), 'Student has no fail-closed global scope');

        // and the explicit scope is what actually isolates a tenant
        $this->assertNull(Student::forAgency($student->agency_id + 999)->whereKey($student->id)->first());
        $this->assertNotNull(Student::forAgency($student->agency_id)->whereKey($student->id)->first());
    }
}
