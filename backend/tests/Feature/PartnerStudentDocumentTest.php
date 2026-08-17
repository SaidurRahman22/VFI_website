<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Enums\SeatRole;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentDocument;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 9D — the agency files on the student's behalf, so it must be able to
 * supply the paperwork. The two things that must hold: the same Phase 5 upload
 * guarantees the student path gives (scan-gate, lock, audit), and a hard tenant
 * fence so an agency can only ever touch its OWN student.
 */
class PartnerStudentDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

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
        PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]);

        return [$agency, $user->fresh()];
    }

    private function student(int $agencyId, string $email): Student
    {
        return Student::create([
            'agency_id' => $agencyId, 'source' => 'partner_modal',
            'email' => $email, 'first_name' => 'Test', 'student_ref' => 'R'.$agencyId.substr(md5($email), 0, 6),
        ]);
    }

    private function asPartner(User $user, int $agencyId): self
    {
        return $this->actingAs($user)->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agencyId]);
    }

    private function pdf(string $body, string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$body."\n%%EOF");
    }

    /** One checklist row by type key — the list carries both packs, so never index by position. */
    private function entry(TestResponse $response, string $type): array
    {
        $row = collect($response->json('data'))->firstWhere('type', $type);
        $this->assertNotNull($row, "No checklist row for '{$type}'.");

        return $row;
    }

    public function test_partner_uploads_for_their_own_student_and_checklist_reports_it(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');

        $created = $this->asPartner($user, $agency->id)
            ->post("/api/partner/students/{$s->id}/documents/passport", ['file' => UploadedFile::fake()->image('p.png', 200, 200)])
            ->assertStatus(201);

        $row = $this->entry($created, 'passport');
        $this->assertSame('uploaded', $row['status']);
        $this->assertSame('application', $row['pack']);
        $this->assertSame('Passport (bio page)', $row['name']);
        $this->assertSame('p.png', $row['file']['original_name']);
        $this->assertSame('clean', $row['file']['scan_status']);
        $this->assertNotNull($row['uploaded_at']);

        // The blob went to the private disk under a server UUID, not the filename.
        $file = DocumentFile::where('student_id', $s->id)->firstOrFail();
        $this->assertStringStartsWith('blob/', $file->storage_key);
        Storage::disk('documents')->assertExists($file->storage_key);

        // A fresh GET agrees, and both packs are present with the rest still missing.
        $listed = $this->asPartner($user, $agency->id)->getJson("/api/partner/students/{$s->id}/documents")->assertStatus(200);
        $this->assertSame('uploaded', $this->entry($listed, 'passport')['status']);
        $this->assertSame('missing', $this->entry($listed, 'sop')['status']);
        $this->assertNull($this->entry($listed, 'sop')['file']);
        $this->assertSame('visa', $this->entry($listed, 'offer')['pack']);
        $this->assertCount(DocumentType::count(), $listed->json('data'));
    }

    public function test_another_agencys_student_is_404_on_every_method(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        $studentA = $this->student($agencyA->id, 'a@a.test');

        // A's student really does have a document — B must still learn nothing.
        $this->asPartner($userA, $agencyA->id)
            ->post("/api/partner/students/{$studentA->id}/documents/passport", ['file' => UploadedFile::fake()->image('p.png')])
            ->assertStatus(201);

        // 404 everywhere, never 403: a 403 would confirm the id exists elsewhere.
        $this->asPartner($userB, $agencyB->id)->getJson("/api/partner/students/{$studentA->id}/documents")->assertStatus(404);
        $this->asPartner($userB, $agencyB->id)
            ->post("/api/partner/students/{$studentA->id}/documents/passport", ['file' => UploadedFile::fake()->image('x.png')])
            ->assertStatus(404);
        $this->asPartner($userB, $agencyB->id)->deleteJson("/api/partner/students/{$studentA->id}/documents/passport")->assertStatus(404);
        $this->asPartner($userB, $agencyB->id)->getJson("/api/partner/students/{$studentA->id}/documents/passport/download")->assertStatus(404);

        // Nothing of A's was touched by any of it.
        $this->assertSame(1, DocumentFile::where('student_id', $studentA->id)->count());
        $this->assertSame(DocumentStatus::Uploaded, StudentDocument::where('student_id', $studentA->id)->firstOrFail()->status);
    }

    public function test_verified_document_is_locked_against_reupload_and_delete(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');
        $type = DocumentType::where('key', 'passport')->firstOrFail();
        StudentDocument::create([
            'student_id' => $s->id, 'document_type_id' => $type->id,
            'status' => DocumentStatus::Verified, 'uploaded_at' => now(),
        ]);

        $this->asPartner($user, $agency->id)
            ->post("/api/partner/students/{$s->id}/documents/passport", ['file' => UploadedFile::fake()->image('p.png')])
            ->assertStatus(409);
        $this->asPartner($user, $agency->id)
            ->deleteJson("/api/partner/students/{$s->id}/documents/passport")
            ->assertStatus(409);

        $this->assertSame(DocumentStatus::Verified, StudentDocument::where('student_id', $s->id)->firstOrFail()->status);
        $this->assertSame(0, DocumentFile::where('student_id', $s->id)->count());   // the refused upload stored nothing
    }

    public function test_reupload_over_a_rejected_document_clears_the_reason(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');

        $this->asPartner($user, $agency->id)
            ->post("/api/partner/students/{$s->id}/documents/passport", ['file' => UploadedFile::fake()->image('blurry.png')])
            ->assertStatus(201);

        // Staff refuse it (Phase 9A write path, simulated here).
        $doc = StudentDocument::where('student_id', $s->id)->firstOrFail();
        $doc->forceFill(['status' => DocumentStatus::Rejected, 'rejection_reason' => 'Bio page is unreadable.'])->save();

        $rejected = $this->asPartner($user, $agency->id)->getJson("/api/partner/students/{$s->id}/documents")->assertStatus(200);
        $this->assertSame('rejected', $this->entry($rejected, 'passport')['status']);
        $this->assertSame('Bio page is unreadable.', $this->entry($rejected, 'passport')['rejection_reason']);

        $replaced = $this->asPartner($user, $agency->id)
            ->post("/api/partner/students/{$s->id}/documents/passport", ['file' => UploadedFile::fake()->image('sharp.png', 400, 400)])
            ->assertStatus(201);

        $row = $this->entry($replaced, 'passport');
        $this->assertSame('uploaded', $row['status']);
        $this->assertNull($row['rejection_reason']);          // the stale refusal is gone
        $this->assertSame('sharp.png', $row['file']['original_name']);
        $this->assertSame(1, StudentDocument::where('student_id', $s->id)->count());   // replaced, not duplicated
    }

    public function test_infected_upload_is_quarantined_and_never_becomes_readable(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');

        $this->asPartner($user, $agency->id)
            ->post("/api/partner/students/{$s->id}/documents/passport", ['file' => $this->pdf(self::EICAR, 'scan.pdf')])
            ->assertStatus(422);

        $file = DocumentFile::where('student_id', $s->id)->firstOrFail();
        $this->assertSame(ScanStatus::Infected, $file->scan_status);
        Storage::disk('documents')->assertMissing($file->storage_key);                  // bytes dropped
        $this->assertSame(0, StudentDocument::where('student_id', $s->id)->count());    // never linked to the checklist
        $this->assertDatabaseHas('document_access_log', ['document_file_id' => $file->id, 'action' => 'quarantine']);

        // Still missing to the console, and no URL can be minted for it.
        $listed = $this->asPartner($user, $agency->id)->getJson("/api/partner/students/{$s->id}/documents")->assertStatus(200);
        $this->assertSame('missing', $this->entry($listed, 'passport')['status']);
        $this->asPartner($user, $agency->id)->getJson("/api/partner/students/{$s->id}/documents/passport/download")->assertStatus(404);
    }

    public function test_every_upload_logs_the_partner_as_the_actor(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');

        $this->asPartner($user, $agency->id)
            ->post("/api/partner/students/{$s->id}/documents/transcripts", ['file' => $this->pdf('marks', 'transcript.pdf')])
            ->assertStatus(201);

        $file = DocumentFile::where('student_id', $s->id)->firstOrFail();
        $this->assertDatabaseHas('document_access_log', [
            'document_file_id' => $file->id, 'student_id' => $s->id,
            'actor_user_id' => $user->id, 'action' => 'upload',      // the agency, not the student
        ]);
        $this->assertDatabaseHas('content_audit_log', [
            'action' => 'partner_document_upload', 'entity' => 'student_document', 'actor_user_id' => $user->id,
        ]);

        // Removing it is attributed too.
        $this->asPartner($user, $agency->id)->deleteJson("/api/partner/students/{$s->id}/documents/transcripts")->assertStatus(200);
        $this->assertDatabaseHas('document_access_log', [
            'document_file_id' => $file->id, 'actor_user_id' => $user->id, 'action' => 'delete',
        ]);
        $this->assertSame(1, StudentDocument::onlyTrashed()->where('student_id', $s->id)->count());   // soft-deleted
        $this->assertSame(1, DocumentFile::where('student_id', $s->id)->count());                     // blob row kept
    }

    public function test_download_mints_a_single_use_short_lived_token(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');
        $this->asPartner($user, $agency->id)
            ->post("/api/partner/students/{$s->id}/documents/passport", ['file' => UploadedFile::fake()->image('p.png')])
            ->assertStatus(201);

        $mint = $this->asPartner($user, $agency->id)
            ->getJson("/api/partner/students/{$s->id}/documents/passport/download")
            ->assertStatus(200)->assertJsonPath('name', 'p.png')->json();
        $this->assertSame((int) config('documents.download_ttl'), $mint['expires_in']);

        // Redeemed by the ONE existing public stream route, and only once.
        $path = parse_url($mint['url'], PHP_URL_PATH);
        $this->assertStringStartsWith('/api/documents/dl/', $path);
        $this->get($path)->assertStatus(200)->assertHeader('Content-Disposition', 'attachment; filename="p.png"');
        $this->get($path)->assertStatus(404);

        $fileId = DocumentFile::where('student_id', $s->id)->value('id');
        $this->assertDatabaseHas('document_access_log', ['document_file_id' => $fileId, 'actor_user_id' => $user->id, 'action' => 'presign']);
        $this->assertDatabaseHas('document_access_log', ['document_file_id' => $fileId, 'action' => 'download']);
    }
}
