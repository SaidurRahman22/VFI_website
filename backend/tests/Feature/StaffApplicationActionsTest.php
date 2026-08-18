<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationNote;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Support\RlsBypass;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the /manage Applications table ACTIONS the way a staff member does,
 * through the Livewire component rather than by calling the service directly.
 *
 * This is the layer that has been missing all along: the services were tested
 * and passed while the screens using them were broken, because nothing ever
 * rendered a Filament page or invoked a table action.
 */
class StaffApplicationActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function staff(): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => Role::StaffPartnerOps->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    private function application(): Application
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh', 'status' => 'approved']);
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'role' => Role::PartnerOwner->value, 'agency_id' => $agency->id, 'granted_at' => now()]);

        app(TenantContext::class)->setAgencyId($agency->id);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => $owner->id,
            'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active,
        ]));
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'pupil@acme.test', 'first_name' => 'Pupil', 'student_ref' => 'R-'.uniqid(),
        ]);
        $app = Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'status' => ApplicationStatus::Submitted->value, 'submitted_at' => now(),
        ]);
        app(TenantContext::class)->clear();

        return RlsBypass::run(fn () => $app->withoutGlobalScope(BelongsToAgencyScope::class)->find($app->id));
    }

    public function test_the_applications_page_renders_for_staff(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        Livewire::test(ListStaffApplications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$app]);
    }

    public function test_the_move_action_advances_the_case(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        Livewire::test(ListStaffApplications::class)
            ->callTableAction('advance', $app, ['to' => ApplicationStatus::Review->value, 'reason' => 'Docs complete'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ApplicationStatus::Review, $app->refresh()->status);
    }

    public function test_the_move_action_refuses_an_illegal_jump(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        // submitted -> visa_received skips the whole pipeline; the guard should
        // hold and the record must not move
        Livewire::test(ListStaffApplications::class)
            ->callTableAction('advance', $app, ['to' => ApplicationStatus::VisaReceived->value]);

        $this->assertSame(ApplicationStatus::Submitted, $app->refresh()->status);
    }

    public function test_the_add_note_action_stores_a_note(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        Livewire::test(ListStaffApplications::class)
            ->callTableAction('addNote', $app, ['body' => 'Rang the admissions desk.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, ApplicationNote::where('application_id', $app->id)->count());
        $this->assertSame('Rang the admissions desk.', ApplicationNote::first()->body);
    }

    public function test_the_notes_action_opens(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        Livewire::test(ListStaffApplications::class)
            ->mountTableAction('viewNotes', $app)
            ->assertOk();
    }
}
