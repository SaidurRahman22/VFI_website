<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\StudentSource;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
use App\Models\Partner\PartnerAgency;
use App\Models\Student\Student;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerConsoleSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->tenant()->clear();
        parent::tearDown();
    }

    public function test_portal_student_still_resolves_self_scoped_with_email_and_source(): void
    {
        // The evolved students table must not break the Phase 5 portal path,
        // which reads with NO tenant in context.
        $this->tenant()->clear();
        $user = User::factory()->create(['name' => 'Ayesha Rahman', 'email' => 'ayesha@example.com']);

        $student = Student::resolveFor($user);

        $this->assertNull($student->agency_id);                       // self-signup = unowned
        $this->assertSame(StudentSource::SelfSignup, $student->source);
        $this->assertSame('ayesha@example.com', $student->email);     // collision key populated
        $this->assertSame('Ayesha Rahman', $student->displayName());
    }

    public function test_students_are_scoped_to_the_session_agency_explicitly(): void
    {
        $a1 = PartnerAgency::create(['legal_name' => 'A1', 'country' => 'Bangladesh']);
        $a2 = PartnerAgency::create(['legal_name' => 'A2', 'country' => 'India']);
        Student::create(['agency_id' => $a1->id, 'source' => 'partner_modal', 'email' => 's1@x.test', 'first_name' => 'S1', 'student_ref' => 'R1']);
        Student::create(['agency_id' => $a2->id, 'source' => 'partner_modal', 'email' => 's2@x.test', 'first_name' => 'S2', 'student_ref' => 'R2']);

        $this->assertSame(1, Student::forAgency($a1->id)->count());
        $this->assertSame('S1', Student::forAgency($a1->id)->first()->first_name);
        $this->assertSame(0, Student::forAgency(999)->count());
    }

    public function test_console_tables_are_tenant_isolated(): void
    {
        $a1 = PartnerAgency::create(['legal_name' => 'A1', 'country' => 'X']);
        $a2 = PartnerAgency::create(['legal_name' => 'A2', 'country' => 'Y']);
        $s1 = Student::create(['agency_id' => $a1->id, 'source' => 'partner_modal', 'email' => 's1@x.test', 'student_ref' => 'R1']);
        $s2 = Student::create(['agency_id' => $a2->id, 'source' => 'partner_modal', 'email' => 's2@x.test', 'student_ref' => 'R2']);

        $this->tenant()->setAgencyId($a1->id);
        Application::create(['agency_id' => $a1->id, 'student_id' => $s1->id, 'status' => ApplicationStatus::Submitted]);
        $this->tenant()->setAgencyId($a2->id);
        Application::create(['agency_id' => $a2->id, 'student_id' => $s2->id, 'status' => ApplicationStatus::Submitted]);

        $this->tenant()->setAgencyId($a1->id);
        $this->assertSame(1, Application::count());                                    // own tenant only
        $this->tenant()->clear();
        $this->assertSame(0, Application::count());                                    // fail-closed
        $this->assertSame(2, Application::withoutGlobalScope(BelongsToAgencyScope::class)->count());
    }

    public function test_status_event_is_append_only_and_auto_stamps_agency(): void
    {
        $a1 = PartnerAgency::create(['legal_name' => 'A1', 'country' => 'X']);
        $s1 = Student::create(['agency_id' => $a1->id, 'source' => 'partner_modal', 'email' => 's1@x.test', 'student_ref' => 'R1']);
        $this->tenant()->setAgencyId($a1->id);
        $app = Application::create(['agency_id' => $a1->id, 'student_id' => $s1->id, 'status' => ApplicationStatus::Submitted]);

        $event = ApplicationStatusEvent::create([
            'application_id' => $app->id, 'to_status' => 'review',
            'occurred_at' => now(), 'actor_type' => ActorType::Partner,
        ]);
        $this->assertSame($a1->id, $event->agency_id);   // stamped from TenantContext

        $this->expectException(\RuntimeException::class);
        $event->update(['to_status' => 'offer']);
    }
}
