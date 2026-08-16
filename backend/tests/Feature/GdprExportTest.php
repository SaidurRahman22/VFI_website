<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ScanStatus;
use App\Models\AuthEvent;
use App\Models\ContentAuditLog;
use App\Models\DataSubjectRequest;
use App\Models\DocumentDisclosure;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
use App\Models\Partner\PartnerAgency;
use App\Models\StaffAccessLog;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentDocument;
use App\Models\Student\StudentProfile;
use App\Models\Student\StudentQualification;
use App\Models\Student\StudentTimelineEvent;
use App\Models\User;
use App\Services\Gdpr\DataSubjectExportService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 9B — the subject access request. What is proved here is as much about
 * what the bundle must NOT contain (credentials, document bytes, storage keys)
 * as about what it must, and that a failed export still leaves a register row.
 */
class GdprExportTest extends TestCase
{
    use RefreshDatabase;

    /** Bytes we would be horrified to find in an export file. */
    private const BLOB_BYTES = 'TOP-SECRET-PASSPORT-SCAN-BYTES';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('documents');
    }

    protected function tearDown(): void
    {
        $this->officer = null;
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function svc(): DataSubjectExportService
    {
        return app(DataSubjectExportService::class);
    }

    private ?User $officer = null;

    /** The data protection officer running the request — one per test. */
    private function officer(): User
    {
        return $this->officer ??= User::factory()->create(['email' => 'dpo@vfi.test']);
    }

    /**
     * One student whose record is spread over every table the export claims to
     * cover, each with a value distinctive enough to find in the JSON.
     */
    private function seededStudent(): Student
    {
        $agency = PartnerAgency::create(['legal_name' => 'Meridian Overseas', 'country' => 'Bangladesh']);
        $user = User::factory()->create(['email' => 'fahmida.rahman@example.test']);

        $student = Student::create([
            'user_id' => $user->id, 'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'fahmida.rahman@example.test', 'first_name' => 'Fahmida', 'last_name' => 'Rahman',
            'student_ref' => 'VFI-2026-04871',
        ]);

        StudentProfile::create([
            'student_id' => $student->id, 'first' => 'Fahmida', 'last' => 'Rahman',
            'nationality' => 'Bangladeshi', 'phone' => '01711000111',
        ]);
        StudentQualification::create([
            'student_id' => $student->id, 'qualification' => 'HSC',
            'institution' => 'Notre Dame College Dhaka', 'year' => '2024', 'grade' => '5.00', 'position' => 0,
        ]);
        StudentTimelineEvent::create([
            'student_id' => $student->id, 'occurred_on' => '2026-03-04', 'tone' => 'good',
            'title' => 'Offer letter received', 'body' => 'Conditional offer from Leeds.', 'position' => 0,
        ]);

        $type = DocumentType::query()->firstOrFail();
        $file = DocumentFile::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'storage_key' => 'blob/e2f1c0de-secret-key', 'original_name' => 'passport-fahmida.pdf',
            'mime' => 'application/pdf', 'size' => 40213, 'sha256' => str_repeat('a1', 32),
            'scan_status' => ScanStatus::Clean->value,
        ]);
        // The real bytes live on the private blob disk — and must stay there.
        Storage::disk('documents')->put($file->storage_key, self::BLOB_BYTES);

        StudentDocument::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'status' => DocumentStatus::Verified->value, 'file_id' => $file->id, 'uploaded_at' => now(),
        ]);
        DocumentAccessLog::record([
            'document_file_id' => $file->id, 'student_id' => $student->id,
            'actor_user_id' => $user->id, 'action' => 'download', 'ip' => '203.0.113.9',
        ]);
        DocumentDisclosure::create([
            'document_file_id' => $file->id, 'student_id' => $student->id,
            'recipient_name' => 'University of Leeds', 'recipient_type' => 'university',
            'lawful_basis' => 'contract', 'note' => 'Sent with the application pack.',
            'disclosed_at' => now(), 'created_at' => now(),
        ]);
        StaffAccessLog::create([
            'actor_user_id' => $this->officer()->id, 'actor_email' => 'counsellor@vfi.test',
            'subject_type' => 'student', 'subject_id' => $student->id, 'subject_agency_id' => $agency->id,
            'reason' => 'Chasing the missing transcript for case 4821.', 'created_at' => now(),
        ]);

        TenantScope::runAs($agency->id, function () use ($agency, $student) {
            $application = Application::create([
                'agency_id' => $agency->id, 'student_id' => $student->id,
                'intake_month' => 'September', 'intake_year' => 2026,
                'status' => ApplicationStatus::Offer->value, 'ack_no' => 'ACK-99120',
            ]);
            ApplicationStatusEvent::create([
                'application_id' => $application->id, 'agency_id' => $agency->id,
                'from_status' => 'submitted', 'to_status' => 'offer', 'occurred_at' => now(),
                'actor_type' => ActorType::Staff->value, 'note' => 'Offer confirmed by the institution.',
            ]);
        });

        return $student->fresh();
    }

    private function bundleJson(DataSubjectRequest $request): string
    {
        return Storage::disk('local')->get($request->artifact_path);
    }

    /** @return array<string,mixed> */
    private function bundle(DataSubjectRequest $request): array
    {
        return json_decode($this->bundleJson($request), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_exporting_a_student_writes_a_bundle_and_completes_the_register_row(): void
    {
        $student = $this->seededStudent();

        $request = $this->svc()->export('student', $student->id, $this->officer(), 'Subject access request by email.');

        $this->assertSame(DataSubjectRequest::TYPE_EXPORT, $request->type);
        $this->assertSame(DataSubjectRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame('fahmida.rahman@example.test', $request->subject_email);
        $this->assertSame('Subject access request by email.', $request->reason);
        $this->assertNotNull($request->completed_at);
        $this->assertNotNull($request->artifact_path);
        $this->assertStringStartsWith('gdpr/exports/', $request->artifact_path);
        $this->assertStringEndsWith('.json', $request->artifact_path);

        Storage::disk('local')->assertExists($request->artifact_path);
        $this->assertTrue($request->isDownloadable());
    }

    public function test_the_bundle_holds_the_students_own_record_across_every_section(): void
    {
        $student = $this->seededStudent();

        $bundle = $this->bundle($this->svc()->export('student', $student->id, $this->officer()));

        $this->assertSame('VFI-2026-04871', $bundle['student']['student_ref']);
        $this->assertSame('Meridian Overseas', $bundle['owning_agency']['legal_name']);
        $this->assertSame('Bangladeshi', $bundle['profile']['nationality']);
        $this->assertSame('Notre Dame College Dhaka', $bundle['qualifications'][0]['institution']);
        $this->assertSame('Offer letter received', $bundle['timeline'][0]['title']);
        $this->assertSame('passport-fahmida.pdf', $bundle['document_files'][0]['original_name']);
        $this->assertSame('verified', $bundle['document_checklist'][0]['status']);
        $this->assertSame('University of Leeds', $bundle['document_disclosures'][0]['recipient_name']);
        $this->assertSame('download', $bundle['document_access_log'][0]['action']);
        $this->assertStringContainsString('case 4821', $bundle['staff_access_log'][0]['reason']);

        // the tenant-scoped pipeline is reached through both nets
        $this->assertSame('ACK-99120', $bundle['pipeline_applications'][0]['ack_no']);
        $this->assertSame('offer', $bundle['pipeline_status_events'][0]['to_status']);

        // and the bundle says what it is and what it deliberately leaves out
        $this->assertSame('student', $bundle['meta']['subject_type']);
        $this->assertSame('dpo@vfi.test', $bundle['meta']['generated_by']);
        $this->assertSame(5000, $bundle['meta']['limits']['rows_per_section']);
        $this->assertNotEmpty($bundle['meta']['note']);
        $this->assertNotEmpty($bundle['meta']['withheld']);
    }

    public function test_credentials_are_never_written_into_the_bundle(): void
    {
        $user = User::factory()->create(['email' => 'admin.person@vfi.test', 'remember_token' => 'REMEMBERTOKEN123']);
        $user->forceFill(['mfa_secret' => 'MFASECRETABC456', 'mfa_enrolled_at' => now()])->save();
        $user->refresh();

        $json = $this->bundleJson($this->svc()->export('user', $user->id, $this->officer()));

        $this->assertStringNotContainsString($user->getRawOriginal('password'), $json, 'the password hash must never be exported');
        $this->assertStringNotContainsString('REMEMBERTOKEN123', $json);
        $this->assertStringNotContainsString('MFASECRETABC456', $json);
        $this->assertStringNotContainsString($user->getRawOriginal('mfa_secret'), $json, 'not even the ciphertext of the MFA secret');

        // the person's non-secret account data IS there
        $bundle = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('admin.person@vfi.test', $bundle['user']['email']);
        $this->assertArrayNotHasKey('password', $bundle['user']);
        $this->assertArrayNotHasKey('remember_token', $bundle['user']);
        $this->assertArrayNotHasKey('mfa_secret', $bundle['user']);
    }

    public function test_documents_are_exported_as_metadata_and_never_as_bytes(): void
    {
        $student = $this->seededStudent();

        $request = $this->svc()->export('student', $student->id, $this->officer());
        $json = $this->bundleJson($request);
        $file = $this->bundle($request)['document_files'][0];

        // the bytes and the pointer to them are both absent
        $this->assertStringNotContainsString(self::BLOB_BYTES, $json);
        $this->assertStringNotContainsString('e2f1c0de-secret-key', $json);
        $this->assertArrayNotHasKey('storage_key', $file);
        $this->assertArrayNotHasKey('contents', $file);

        // the description of the file is complete
        $this->assertSame('passport-fahmida.pdf', $file['original_name']);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertSame(40213, $file['size_bytes']);
        $this->assertSame(str_repeat('a1', 32), $file['sha256']);
        $this->assertSame('clean', $file['scan_status']);
        $this->assertArrayHasKey('retention_until', $file);
        $this->assertArrayHasKey('bytes_deleted_at', $file);

        // and the blob itself is untouched — an export deletes nothing
        $this->assertSame(self::BLOB_BYTES, Storage::disk('documents')->get('blob/e2f1c0de-secret-key'));
    }

    public function test_a_failed_export_still_leaves_the_request_on_the_register(): void
    {
        $officer = $this->officer();

        try {
            $this->svc()->export('student', 987654, $officer, 'Request received at the front desk.');
            $this->fail('exporting a subject that does not exist must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No such student', $e->getMessage());
        }

        $request = DataSubjectRequest::query()->sole();
        $this->assertSame(DataSubjectRequest::STATUS_FAILED, $request->status);
        $this->assertSame(987654, (int) $request->subject_id);
        $this->assertSame($officer->id, $request->requested_by_user_id);
        $this->assertSame('Request received at the front desk.', $request->reason);
        $this->assertNotEmpty($request->outcome);
        $this->assertNull($request->artifact_path);
        $this->assertNull($request->completed_at);
        $this->assertFalse($request->isDownloadable());
    }

    public function test_a_user_export_carries_their_roles_auth_history_and_student_record(): void
    {
        $student = $this->seededStudent();
        $user = User::query()->findOrFail($student->user_id);
        AuthEvent::record('student_login_success', ['user_id' => $user->id, 'email' => $user->email, 'ip' => '198.51.100.4']);

        $bundle = $this->bundle($this->svc()->export('user', $user->id, $this->officer()));

        $this->assertSame($user->email, $bundle['user']['email']);
        $this->assertSame('student_login_success', $bundle['auth_events'][0]['event']);
        $this->assertSame('198.51.100.4', $bundle['auth_events'][0]['ip']);
        $this->assertArrayHasKey('student_record', $bundle);
        $this->assertSame('VFI-2026-04871', $bundle['student_record']['student']['student_ref']);
    }

    public function test_the_export_is_audited_by_shape_not_by_content(): void
    {
        $student = $this->seededStudent();

        $request = $this->svc()->export('student', $student->id, $this->officer());

        $audit = ContentAuditLog::query()->where('action', 'gdpr_export')
            ->where('entity', 'data_subject')->where('entity_id', (string) $student->id)->sole();

        $this->assertSame($request->id, $audit->after['request_id']);
        $this->assertSame('student', $audit->after['subject_type']);
        $this->assertSame($request->artifact_path, $audit->after['artifact_path']);
        $this->assertSame(1, $audit->after['sections']['qualifications']);
        $this->assertSame([], $audit->after['truncated_sections']);

        // the audit row is a receipt, not a second copy of the record
        $this->assertStringNotContainsString('Notre Dame', json_encode($audit->after));
        $this->assertStringNotContainsString('Fahmida', json_encode($audit->after));
    }

    public function test_only_a_student_or_a_user_can_be_a_subject(): void
    {
        try {
            $this->svc()->export('agency', 1, $this->officer());
            $this->fail('an unknown subject type must be refused');
        } catch (\RuntimeException) {
            // expected
        }

        // refused before anything is written: no half-open request on the register
        $this->assertSame(0, DataSubjectRequest::query()->count());
    }

    public function test_a_log_section_is_capped_newest_first_and_the_bundle_admits_it(): void
    {
        $student = $this->seededStudent();
        $fileId = DocumentFile::query()->value('id');

        // One row more than the cap, so the cap has to bite. Written straight to
        // the table: the point is the volume, not the app path.
        foreach (array_chunk(range(1, 5001), 500) as $chunk) {
            DB::table('document_access_log')->insert(array_map(fn () => [
                'document_file_id' => $fileId, 'student_id' => $student->id,
                'action' => 'presign', 'created_at' => now(),
            ], $chunk));
        }

        $request = $this->svc()->export('student', $student->id, $this->officer());
        $bundle = $this->bundle($request);

        $this->assertCount(5000, $bundle['document_access_log']);
        $this->assertContains('document_access_log', $bundle['meta']['limits']['truncated_sections']);
        $this->assertStringContainsString('Capped at 5000', $request->outcome);

        // newest first, so the oldest row (the seeded download) is the one that fell off
        $this->assertNotContains('download', array_column($bundle['document_access_log'], 'action'));
    }

    public function test_nothing_is_deleted_by_an_export(): void
    {
        $student = $this->seededStudent();
        $before = [
            'files' => DocumentFile::query()->count(),
            'documents' => StudentDocument::query()->count(),
            'access' => DocumentAccessLog::query()->count(),
            'disclosures' => DocumentDisclosure::query()->count(),
        ];

        $this->svc()->export('student', $student->id, $this->officer());

        $this->assertSame($before['files'], DocumentFile::query()->count());
        $this->assertSame($before['documents'], StudentDocument::query()->count());
        $this->assertSame($before['access'], DocumentAccessLog::query()->count());
        $this->assertSame($before['disclosures'], DocumentDisclosure::query()->count());
        $this->assertNotNull(Student::query()->find($student->id));
    }
}
