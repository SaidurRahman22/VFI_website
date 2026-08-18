<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentDocument;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'a-good-long-passphrase';

    private const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function loginStudent(bool $verified = true): User
    {
        $u = User::factory()->create([
            'password' => self::PW,
            'email_verified_at' => $verified ? now() : null,
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => self::PW])->assertStatus(200);

        return $u->fresh();
    }

    private function pdf(string $body, string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$body."\n%%EOF");
    }

    public function test_valid_upload_is_scanned_clean_and_becomes_uploaded(): void
    {
        $u = $this->loginStudent();

        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->image('p.png', 200, 200)])
            ->assertStatus(201);

        $student = Student::resolveFor($u);
        $doc = StudentDocument::where('student_id', $student->id)->firstOrFail();
        $this->assertSame(DocumentStatus::Uploaded, $doc->status);
        $this->assertSame(ScanStatus::Clean, $doc->file->scan_status);
        Storage::disk('documents')->assertExists($doc->file->storage_key);   // stored under a UUID key
        $this->assertStringStartsWith('blob/', $doc->file->storage_key);
        $this->assertDatabaseHas('document_access_log', ['document_file_id' => $doc->file_id, 'action' => 'upload']);
    }

    public function test_eicar_is_quarantined_and_never_downloadable(): void
    {
        $u = $this->loginStudent();

        $this->post('/api/me/documents/passport', ['file' => $this->pdf(self::EICAR, 'scan.pdf')])
            ->assertStatus(422);

        $student = Student::resolveFor($u);
        $file = DocumentFile::where('student_id', $student->id)->firstOrFail();
        $this->assertSame(ScanStatus::Infected, $file->scan_status);
        Storage::disk('documents')->assertMissing($file->storage_key);       // bytes dropped
        $this->assertSame(0, StudentDocument::where('student_id', $student->id)->count());  // never linked
        $this->assertDatabaseHas('document_access_log', ['document_file_id' => $file->id, 'action' => 'quarantine']);

        // checklist still shows missing; download refuses
        $this->getJson('/api/me/documents')->assertJsonPath('application.0.status', 'missing');
        $this->getJson('/api/me/documents/passport/download')->assertStatus(404);
    }

    public function test_oversize_and_wrong_type_are_rejected_server_side(): void
    {
        $this->loginStudent();

        // 20 MB > 15 MB cap
        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->create('big.pdf', 20000)])
            ->assertStatus(422);

        // text file — not in the pdf/jpg/png allow-list (the <input> has no accept attr)
        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->createWithContent('note.txt', 'just some plain text here')])
            ->assertStatus(422);
    }

    public function test_download_is_single_use_and_logged(): void
    {
        $this->loginStudent();
        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->image('p.png')])->assertStatus(201);

        $mint = $this->getJson('/api/me/documents/passport/download')->assertStatus(200)->json();
        $path = parse_url($mint['url'], PHP_URL_PATH);

        $this->get($path)->assertStatus(200)->assertHeader('Content-Disposition', 'attachment; filename="p.png"');
        $this->get($path)->assertStatus(404);   // single-use: the token is spent

        $fileId = DocumentFile::first()->id;
        $this->assertDatabaseHas('document_access_log', ['document_file_id' => $fileId, 'action' => 'presign']);
        $this->assertDatabaseHas('document_access_log', ['document_file_id' => $fileId, 'action' => 'download']);
    }

    public function test_download_link_expires(): void
    {
        $this->loginStudent();
        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->image('p.png')])->assertStatus(201);
        $path = parse_url($this->getJson('/api/me/documents/passport/download')->json('url'), PHP_URL_PATH);

        $this->travel((int) config('documents.download_ttl') + 5)->seconds();
        $this->get($path)->assertStatus(404);
    }

    public function test_unverified_student_cannot_upload(): void
    {
        $this->loginStudent(verified: false);

        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->image('p.png')])
            ->assertStatus(403)->assertJsonPath('must_verify', true);
    }

    public function test_filename_never_builds_the_storage_path(): void
    {
        $u = $this->loginStudent();
        $this->post('/api/me/documents/passport', ['file' => $this->pdf('hello', '../../etc/evil.pdf')])
            ->assertStatus(201);

        $file = DocumentFile::where('student_id', Student::resolveFor($u)->id)->firstOrFail();
        $this->assertStringNotContainsString('/', $file->original_name);
        $this->assertStringNotContainsString('..', $file->original_name);
        $this->assertStringStartsWith('blob/', $file->storage_key);   // key is a UUID, not the name
    }

    public function test_delete_soft_deletes_and_re_upload_restores(): void
    {
        $u = $this->loginStudent();
        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->image('p.png')])->assertStatus(201);
        $student = Student::resolveFor($u);

        $this->deleteJson('/api/me/documents/passport')->assertStatus(200)->assertJsonPath('application.0.status', 'missing');
        $this->assertSame(1, StudentDocument::onlyTrashed()->where('student_id', $student->id)->count());  // soft-deleted
        $this->assertSame(1, DocumentFile::where('student_id', $student->id)->count());                    // blob kept

        // re-upload restores the row (no unique-constraint violation)
        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->image('p2.png')])
            ->assertStatus(201)->assertJsonPath('application.0.status', 'uploaded');
        $this->assertSame(1, StudentDocument::where('student_id', $student->id)->count());
    }

    public function test_verified_document_is_locked(): void
    {
        $u = $this->loginStudent();
        $student = Student::resolveFor($u);
        $type = DocumentType::where('key', 'passport')->firstOrFail();
        StudentDocument::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'status' => DocumentStatus::Verified, 'uploaded_at' => now(),
        ]);

        $this->deleteJson('/api/me/documents/passport')->assertStatus(409);
        $this->post('/api/me/documents/passport', ['file' => UploadedFile::fake()->image('p.png')])->assertStatus(409);
    }

    public function test_idempotent_reupload_reuses_the_blob(): void
    {
        $u = $this->loginStudent();
        $img = UploadedFile::fake()->image('same.png', 150, 150);
        $bytes = file_get_contents($img->getRealPath());

        $mk = fn () => UploadedFile::fake()->createWithContent('same.png', $bytes);
        $this->post('/api/me/documents/passport', ['file' => $mk()])->assertStatus(201);
        $this->post('/api/me/documents/passport', ['file' => $mk()])->assertStatus(201);

        $student = Student::resolveFor($u);
        $this->assertSame(1, DocumentFile::where('student_id', $student->id)->count());   // no duplicate blob
    }
}
