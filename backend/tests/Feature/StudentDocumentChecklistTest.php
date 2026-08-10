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
use Tests\TestCase;

class StudentDocumentChecklistTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'a-good-long-passphrase';

    private function loginStudent(): User
    {
        $u = User::factory()->create(['password' => self::PW, 'email_verified_at' => now()]);
        UserRole::create(['user_id' => $u->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => self::PW])->assertStatus(200);

        return $u->fresh();
    }

    public function test_checklist_returns_both_packs_with_defaults(): void
    {
        $this->loginStudent();

        $res = $this->getJson('/api/me/documents')->assertStatus(200)
            ->assertJsonCount(6, 'application')
            ->assertJsonCount(6, 'visa');

        $keys = collect($res->json('application'))->pluck('key')->all();
        $this->assertSame(['passport', 'transcripts', 'sop', 'lor', 'financials', 'testreport'], $keys);

        // every type defaults to missing with no file
        $this->assertSame('missing', $res->json('application.0.status'));
        $this->assertNull($res->json('application.0.file'));

        // medical is flagged destination-dependent
        $medical = collect($res->json('visa'))->firstWhere('key', 'medical');
        $this->assertTrue($medical['destination_dependent']);
    }

    public function test_checklist_reflects_this_students_uploaded_document(): void
    {
        $u = $this->loginStudent();
        $student = Student::resolveFor($u);
        $type = DocumentType::where('key', 'passport')->firstOrFail();

        $file = DocumentFile::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'storage_key' => 'k', 'original_name' => 'passport.pdf',
            'mime' => 'application/pdf', 'size' => 2048, 'scan_status' => ScanStatus::Clean,
        ]);
        StudentDocument::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'status' => DocumentStatus::Uploaded, 'file_id' => $file->id, 'uploaded_at' => now(),
        ]);

        $res = $this->getJson('/api/me/documents')->assertStatus(200);
        $passport = collect($res->json('application'))->firstWhere('key', 'passport');
        $this->assertSame('uploaded', $passport['status']);
        $this->assertSame('passport.pdf', $passport['file']['name']);
        $this->assertTrue($passport['file']['readable']);
    }
}
