<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\ContentAuditLog;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationNote;
use App\Models\Partner\ApplicationStatusEvent;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\PartnerNotification;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ApplicationReviewService;
use App\Support\RlsBypass;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9A slice 2 — staff application authoring. Phase 7's transition() had no
 * caller; these pin the guarded staff path around it.
 */
class ApplicationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function application(string $status = 'submitted'): Application
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh']);
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $owner->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]));

        app(TenantContext::class)->setAgencyId($agency->id);
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal', 'email' => uniqid().'@s.test',
            'first_name' => 'Test', 'student_ref' => 'R'.uniqid(),
        ]);
        $app = Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'status' => $status, 'submitted_at' => now(),
        ]);
        app(TenantContext::class)->clear();   // staff act with NO tenant

        return RlsBypass::run(fn () => $app->withoutGlobalScope(BelongsToAgencyScope::class)->find($app->id));
    }

    private function staff(): User
    {
        return User::factory()->create(['name' => 'Case Officer']);
    }

    public function test_a_legal_transition_moves_the_case_and_writes_one_event(): void
    {
        $app = $this->application('submitted');

        app(ApplicationReviewService::class)->transition($app, ApplicationStatus::Review, $this->staff());

        $this->assertSame(ApplicationStatus::Review, $app->refresh()->status);
        // status events are tenant-scoped and staff hold no tenant, so the
        // assertion opts out of the scope the same way the staff screen does
        $this->assertSame(1, ApplicationStatusEvent::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('application_id', $app->id)->where('to_status', 'review')->count());
    }

    public function test_an_illegal_jump_is_refused(): void
    {
        $app = $this->application('submitted');

        $this->expectException(\RuntimeException::class);
        // submitted → visa_received skips the entire pipeline
        app(ApplicationReviewService::class)->transition($app, ApplicationStatus::VisaReceived, $this->staff());
    }

    public function test_negative_outcomes_require_a_reason(): void
    {
        $app = $this->application('submitted');

        $this->expectException(\RuntimeException::class);
        app(ApplicationReviewService::class)->transition($app, ApplicationStatus::NonEnrolment, $this->staff(), '  ');
    }

    public function test_moving_to_the_same_status_is_refused(): void
    {
        $app = $this->application('review');

        $this->expectException(\RuntimeException::class);
        app(ApplicationReviewService::class)->transition($app, ApplicationStatus::Review, $this->staff());
    }

    public function test_the_owning_agency_is_notified_and_the_move_is_audited(): void
    {
        $app = $this->application('submitted');
        $staff = $this->staff();

        app(ApplicationReviewService::class)->transition($app, ApplicationStatus::Review, $staff, 'Docs look complete');

        $note = PartnerNotification::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $app->agency_id)->where('title', 'Application updated')->first();
        $this->assertNotNull($note, 'the owning agency must be told');

        $audit = ContentAuditLog::where('entity', 'application')->where('entity_id', (string) $app->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('submitted', $audit->before['status']);
        $this->assertSame('review', $audit->after['status']);
        $this->assertSame($staff->id, $audit->after['actor_user_id']);
    }

    public function test_allowed_next_statuses_drive_the_ui(): void
    {
        $svc = app(ApplicationReviewService::class);

        $next = collect($svc->allowedNextStatuses(ApplicationStatus::Submitted))->map(fn ($s) => $s->value)->all();
        $this->assertContains('review', $next);
        $this->assertNotContains('visa_received', $next);

        $this->assertTrue($svc->canTransition(ApplicationStatus::Offer, ApplicationStatus::Payment));
        $this->assertFalse($svc->canTransition(ApplicationStatus::Offer, ApplicationStatus::VisaReceived));
    }

    public function test_notes_are_append_only_and_attributed(): void
    {
        $app = $this->application();
        $staff = $this->staff();
        $svc = app(ApplicationReviewService::class);

        $svc->addNote($app, $staff, 'Called the university admissions desk.');
        $svc->addNote($app, $staff, 'Correction: spoke to the faculty office.');

        $notes = ApplicationNote::where('application_id', $app->id)->orderBy('id')->get();
        $this->assertCount(2, $notes);                       // corrections are new rows
        $this->assertSame('Case Officer', $notes[0]->author_name);
        $this->assertNotNull($notes[0]->created_at);
    }

    public function test_an_empty_note_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ApplicationReviewService::class)->addNote($this->application(), $this->staff(), "  \n ");
    }

    public function test_internal_notes_never_reach_the_partner_console(): void
    {
        $app = $this->application();
        $staff = $this->staff();
        app(ApplicationReviewService::class)->addNote($app, $staff, 'SENSITIVE-INTERNAL-STRING');

        // sign in as the owning agency's user and read the console list
        $owner = User::whereHas('roles', fn ($q) => $q->where('agency_id', $app->agency_id))->firstOrFail();
        $res = $this->actingAs($owner)
            ->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $app->agency_id])
            ->getJson('/api/partner/applications')->assertStatus(200);

        $this->assertStringNotContainsString('SENSITIVE-INTERNAL-STRING', $res->getContent());
    }
}
