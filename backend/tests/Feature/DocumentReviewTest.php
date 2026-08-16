<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\ScanStatus;
use App\Models\ContentAuditLog;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentDocument;
use App\Models\Student\StudentTimelineEvent;
use App\Models\User;
use App\Services\DocumentReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9A — the staff document review path. Phase 5 could never leave
 * `uploaded`; these pin the write path, its guards and its audit trail.
 */
class DocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function doc(string $scan = ScanStatus::Clean->value, string $status = DocumentStatus::Uploaded->value): StudentDocument
    {
        $user = User::factory()->create();
        $student = Student::resolveFor($user);
        $type = DocumentType::first() ?? DocumentType::create([
            'key' => 'passport', 'pack' => 'application', 'name' => 'Passport', 'position' => 1,
        ]);

        $file = DocumentFile::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'storage_key' => 'docs/'.uniqid().'.pdf', 'original_name' => 'passport.pdf',
            'mime' => 'application/pdf', 'size' => 1024, 'scan_status' => $scan,
        ]);

        return StudentDocument::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'status' => $status, 'file_id' => $file->id, 'uploaded_at' => now(),
        ]);
    }

    public function test_verify_marks_it_verified_with_attribution(): void
    {
        $doc = $this->doc();
        $staff = $this->staff();

        app(DocumentReviewService::class)->verify($doc, $staff);

        $doc->refresh();
        $this->assertSame(DocumentStatus::Verified, $doc->status);
        $this->assertSame($staff->id, $doc->verified_by);
        $this->assertNotNull($doc->verified_at);
        $this->assertNull($doc->rejection_reason);
    }

    public function test_reject_requires_a_reason_and_shows_it_to_the_student(): void
    {
        $doc = $this->doc();

        app(DocumentReviewService::class)->reject($doc, $this->staff(), 'The photo page is cut off.');

        $doc->refresh();
        $this->assertSame(DocumentStatus::Rejected, $doc->status);
        $this->assertSame('The photo page is cut off.', $doc->rejection_reason);

        // the student is told, verbatim, on their timeline
        $ev = StudentTimelineEvent::where('student_id', $doc->student_id)->latest('id')->first();
        $this->assertNotNull($ev);
        $this->assertStringContainsString('The photo page is cut off.', $ev->body);
        $this->assertSame('bad', $ev->tone);
    }

    public function test_reject_with_a_blank_reason_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        app(DocumentReviewService::class)->reject($this->doc(), $this->staff(), '   ');
    }

    public function test_only_an_uploaded_document_can_be_reviewed(): void
    {
        $doc = $this->doc(status: DocumentStatus::Verified->value);

        $this->expectException(\RuntimeException::class);
        app(DocumentReviewService::class)->verify($doc, $this->staff());
    }

    public function test_a_document_that_has_not_passed_the_scan_cannot_be_verified(): void
    {
        $doc = $this->doc(scan: ScanStatus::Pending->value);

        $this->expectException(\RuntimeException::class);
        app(DocumentReviewService::class)->verify($doc, $this->staff());
    }

    public function test_reopen_unlocks_a_mistakenly_verified_document(): void
    {
        $doc = $this->doc();
        $staff = $this->staff();
        app(DocumentReviewService::class)->verify($doc, $staff);

        app(DocumentReviewService::class)->reopen($doc->refresh(), $staff, 'Verified the wrong student.');

        $doc->refresh();
        $this->assertSame(DocumentStatus::Uploaded, $doc->status);
        $this->assertNull($doc->verified_by);
        $this->assertNull($doc->verified_at);
    }

    public function test_every_decision_is_double_audited(): void
    {
        $doc = $this->doc();
        $staff = $this->staff();

        app(DocumentReviewService::class)->verify($doc, $staff);

        // content audit carries the before/after
        $audit = ContentAuditLog::where('entity', 'student_document')->where('entity_id', (string) $doc->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('verify', $audit->action);
        $this->assertSame('uploaded', $audit->before['status']);
        $this->assertSame('verified', $audit->after['status']);

        // and the document access log records who touched the file
        $this->assertSame(1, DocumentAccessLog::where('document_file_id', $doc->file_id)
            ->where('action', 'verify')->where('actor_user_id', $staff->id)->count());
    }

    public function test_staff_file_download_requires_an_admin_session(): void
    {
        $doc = $this->doc();

        // anonymous → redirected to the admin login, never the bytes
        $this->get("/manage-files/documents/{$doc->id}")->assertRedirect('/admin-login.html');

        // a signed-in NON-admin is refused by EnsureAdmin
        $this->actingAs(User::factory()->create())
            ->get("/manage-files/documents/{$doc->id}")->assertStatus(403);
    }
}
