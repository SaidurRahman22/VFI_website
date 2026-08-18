<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Enums\SeatRole;
use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Models\Concerns\BelongsToAgencyScope;
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
use App\Support\RlsBypass;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The paperwork half of the /manage Applications queue.
 *
 * Staff are asked to "check the documents, then move the case", but the queue
 * showed nothing about a student's documents at all, so the check could not
 * happen on this screen. These drive the Livewire component the way a browser
 * does — the services were green all along while the screen using them was not.
 */
class StaffApplicationDocumentsTest extends TestCase
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

    /** A content editor holds an admin-panel role but NOT applications.process. */
    private function contentEditor(): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => Role::ContentEditor->value, 'granted_at' => now()]);

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

    /** A clean, readable upload sitting in one of the six application slots. */
    private function upload(int $studentId, string $typeKey = 'passport'): StudentDocument
    {
        $type = DocumentType::where('key', $typeKey)->firstOrFail();

        $file = DocumentFile::create([
            'student_id' => $studentId, 'document_type_id' => $type->id,
            'storage_key' => 'documents/'.uniqid(), 'original_name' => 'passport.pdf',
            'mime' => 'application/pdf', 'size' => 240_000, 'sha256' => str_repeat('a', 64),
            'scan_status' => ScanStatus::Clean,
        ]);

        return StudentDocument::create([
            'student_id' => $studentId, 'document_type_id' => $type->id,
            'status' => DocumentStatus::Uploaded, 'file_id' => $file->id,
            'uploaded_at' => now(),
        ]);
    }

    public function test_the_queue_shows_a_documents_column(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        Livewire::test(ListStaffApplications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$app])
            ->assertTableColumnExists('documents_readiness');
    }

    /** The badge reads ApplicationReadiness, so this runs against the real one. */
    public function test_the_badge_counts_the_students_documents(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();
        $this->upload($app->student_id);

        $instances = 0;
        $this->app->bind(ApplicationReadiness::class, function () use (&$instances) {
            $instances++;

            return new ApplicationReadiness;
        });

        Livewire::test(ListStaffApplications::class)
            ->assertOk()
            ->assertTableColumnStateSet('documents_readiness', '1/6', $app);

        // The service memoises document_types on the instance it is asked on, so
        // the page has to share ONE: resolving it per row would turn static
        // reference data into a query for every case in the queue.
        $this->assertSame(1, $instances);
    }

    public function test_the_documents_action_opens_for_a_case_with_no_paperwork(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        // The empty case is the one staff most need to see: nothing to process.
        Livewire::test(ListStaffApplications::class)
            ->mountTableAction('documents', $app)
            ->assertOk()
            ->assertMountedActionModalSee('NOT READY')
            ->assertMountedActionModalSee('Passport (bio page)');
    }

    public function test_the_documents_action_opens_for_a_case_with_an_upload(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();
        $doc = $this->upload($app->student_id);

        Livewire::test(ListStaffApplications::class)
            ->mountTableAction('documents', $app)
            ->assertOk()
            ->assertMountedActionModalSee('passport.pdf')
            ->assertMountedActionModalSee('Open file')
            // the existing staff route, not a second download path
            ->assertMountedActionModalSee(route('staff.documents.download', $doc->id));
    }

    public function test_a_staff_member_without_the_ability_cannot_reach_the_queue(): void
    {
        $this->actingAs($this->contentEditor());
        $this->application();

        Livewire::test(ListStaffApplications::class)->assertForbidden();
    }
}
