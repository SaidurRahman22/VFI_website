<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Enums\SeatRole;
use App\Models\Catalogue\Institution;
use App\Models\Catalogue\Program;
use App\Models\Partner\Application;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentDocument;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ApplicationReadiness;
use App\Services\DocumentReviewService;
use App\Services\PipelineService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9D — an application is only processable with the student's paperwork
 * behind it. These prove the readiness verdict itself, and that the case view
 * and the create response both carry it.
 */
class ApplicationReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** The six 'application' types, none of them destination-dependent. */
    private const REQUIRED = ['passport', 'transcripts', 'sop', 'lor', 'financials', 'testreport'];

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

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
        return Student::create([
            'agency_id' => $agencyId, 'source' => 'partner_modal', 'email' => $email,
            'first_name' => 'Test', 'last_name' => 'Lead', 'student_ref' => 'R'.uniqid(),
        ]);
    }

    /** A scan-clean blob linked to the student's checklist slot, as an upload leaves it. */
    private function upload(Student $student, string $key): StudentDocument
    {
        $type = DocumentType::where('key', $key)->firstOrFail();

        $file = DocumentFile::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'storage_key' => 'k-'.$key.'-'.$student->id, 'original_name' => $key.'.pdf',
            'mime' => 'application/pdf', 'size' => 1024, 'sha256' => hash('sha256', $key.$student->id),
            'scan_status' => ScanStatus::Clean,
        ]);

        return StudentDocument::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'status' => DocumentStatus::Uploaded, 'file_id' => $file->id, 'uploaded_at' => now(),
        ]);
    }

    private function readiness(Student $student): array
    {
        return app(ApplicationReadiness::class)->for($student);
    }

    public function test_a_student_with_no_documents_is_not_ready_and_is_missing_everything(): void
    {
        [$agency] = $this->agencyOwner('A');
        $r = $this->readiness($this->student($agency->id, 'none@x.test'));

        $this->assertFalse($r['ready']);
        $this->assertFalse($r['complete']);
        $this->assertSame(self::REQUIRED, $r['required']);
        $this->assertSame(self::REQUIRED, $r['missing']);
        $this->assertSame([], $r['present']);
        $this->assertSame([], $r['verified']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_uploading_every_required_type_is_ready_but_not_complete(): void
    {
        [$agency] = $this->agencyOwner('A');
        $student = $this->student($agency->id, 'up@x.test');
        foreach (self::REQUIRED as $key) {
            $this->upload($student, $key);
        }

        $r = $this->readiness($student);

        $this->assertTrue($r['ready']);
        $this->assertFalse($r['complete']);      // uploaded is not checked
        $this->assertSame(self::REQUIRED, $r['present']);
        $this->assertSame([], $r['missing']);
        $this->assertSame([], $r['verified']);
    }

    public function test_verifying_every_required_type_is_complete(): void
    {
        [$agency] = $this->agencyOwner('A');
        $student = $this->student($agency->id, 'ver@x.test');
        $staff = User::factory()->create();
        $review = app(DocumentReviewService::class);

        foreach (self::REQUIRED as $key) {
            $review->verify($this->upload($student, $key), $staff);
        }

        $r = $this->readiness($student);

        $this->assertTrue($r['ready']);
        $this->assertTrue($r['complete']);
        $this->assertSame(self::REQUIRED, $r['verified']);
        $this->assertSame(self::REQUIRED, $r['present']);
    }

    public function test_a_rejected_document_is_surfaced_and_drops_readiness(): void
    {
        [$agency] = $this->agencyOwner('A');
        $student = $this->student($agency->id, 'rej@x.test');
        $staff = User::factory()->create();

        $docs = [];
        foreach (self::REQUIRED as $key) {
            $docs[$key] = $this->upload($student, $key);
        }
        app(DocumentReviewService::class)->reject($docs['passport'], $staff, 'The scan is unreadable.');

        $r = $this->readiness($student);

        $this->assertFalse($r['ready']);
        $this->assertFalse($r['complete']);
        $this->assertSame(['passport'], $r['rejected']);
        $this->assertNotContains('passport', $r['present']);
        // Rejected is its own bucket: the agency must REPLACE that file, which is
        // a different instruction from "go and collect one".
        $this->assertSame([], $r['missing']);
    }

    public function test_show_returns_the_history_oldest_first_with_readiness(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        app(TenantContext::class)->setAgencyId($agency->id);

        $student = $this->student($agency->id, 'show@x.test');
        $this->upload($student, 'passport');

        $pipeline = app(PipelineService::class);
        $app = $pipeline->create($student, ['intake_month' => 'September', 'intake_year' => 2026], $user->id);
        $pipeline->transition($app, ApplicationStatus::Review, ActorType::Staff, $user->id, 'Docs being checked');
        $pipeline->transition($app, ApplicationStatus::Offer, ActorType::Staff, $user->id, 'Offer issued');

        $res = $this->asPartner($user, $agency->id)->getJson('/api/partner/applications/'.$app->id)
            ->assertStatus(200)
            ->assertJsonPath('application.id', $app->id)
            ->assertJsonPath('application.status', 'offer')
            ->assertJsonPath('application.student.email', 'show@x.test')
            ->assertJsonPath('application.program', null)
            ->assertJsonPath('readiness.ready', false)
            ->assertJsonCount(3, 'events');

        $this->assertSame(
            ['submitted', 'review', 'offer'],
            collect($res->json('events'))->pluck('to')->all(),
        );
        $this->assertNull($res->json('events.0.from'));
        $this->assertSame('review', $res->json('events.1.to'));
        $this->assertSame(['passport'], $res->json('readiness.present'));
        $this->assertSame($student->student_ref.'-A'.$app->id, $res->json('application.public_ref'));
    }

    public function test_show_names_the_program_and_its_university_when_one_is_attached(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        app(TenantContext::class)->setAgencyId($agency->id);

        $uni = Institution::create(['name' => 'University of Toronto', 'country' => 'Canada']);
        $program = Program::create(['institution_id' => $uni->id, 'title' => 'MSc Computer Science', 'level' => 'master']);

        $app = app(PipelineService::class)->create(
            $this->student($agency->id, 'prog@x.test'), ['program_id' => $program->id], $user->id,
        );

        $this->asPartner($user, $agency->id)->getJson('/api/partner/applications/'.$app->id)
            ->assertStatus(200)
            ->assertJsonPath('application.program.id', $program->id)
            ->assertJsonPath('application.program.title', 'MSc Computer Science')
            ->assertJsonPath('application.program.university', 'University of Toronto');
    }

    public function test_show_404s_for_another_agencys_application(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');

        app(TenantContext::class)->setAgencyId($agencyB->id);
        $appB = app(PipelineService::class)->create($this->student($agencyB->id, 'b@x.test'), [], $userB->id);

        // A must not be able to read — or even confirm the existence of — B's case.
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/applications/'.$appB->id)
            ->assertStatus(404);
    }

    public function test_store_still_succeeds_with_no_documents_and_reports_readiness(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        $student = $this->student($agency->id, 'new@x.test');

        $res = $this->asPartner($user, $agency->id)->postJson('/api/partner/applications', [
            'student_id' => $student->id, 'intake_month' => 'September', 'intake_year' => 2026,
        ])
            ->assertStatus(201)
            ->assertJsonPath('application.status', 'submitted')
            ->assertJsonPath('readiness.ready', false)
            ->assertJsonPath('readiness.complete', false);

        $this->assertSame(self::REQUIRED, $res->json('readiness.missing'));

        app(TenantContext::class)->setAgencyId($agency->id);
        $this->assertSame(1, Application::where('student_id', $student->id)->count());
    }
}
