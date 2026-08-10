<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\ScanStatus;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentAction;
use App\Models\Student\StudentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDataLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_types_are_seeded_by_migration(): void
    {
        $this->assertSame(12, DocumentType::count());
        $this->assertSame(6, DocumentType::where('pack', 'application')->count());
        $this->assertSame(6, DocumentType::where('pack', 'visa')->count());
        $this->assertTrue(DocumentType::where('key', 'medical')->value('destination_dependent'));
    }

    public function test_resolve_for_creates_one_student_with_display_ref(): void
    {
        $user = User::factory()->create();

        $a = Student::resolveFor($user);
        $b = Student::resolveFor($user);   // idempotent

        $this->assertTrue($a->is($b));
        $this->assertSame(1, Student::where('user_id', $user->id)->count());
        $this->assertMatchesRegularExpression('/^VFI-\d{4}-\d{5}$/', $a->student_ref);
    }

    public function test_profile_relations_wire_up(): void
    {
        $user = User::factory()->create(['name' => 'Ayesha Rahman']);
        $student = Student::resolveFor($user);
        $student->profile()->create(['first' => 'Ayesha', 'last' => 'Rahman', 'email' => $user->email]);
        $student->qualifications()->create(['qualification' => 'BSc', 'institution' => 'DCU', 'year' => '2025', 'grade' => 'A', 'position' => 0]);
        $student->destinations()->create(['destination' => 'United Kingdom', 'position' => 0]);

        $student->refresh();
        $this->assertSame('Ayesha Rahman', $student->displayName());
        $this->assertSame('AR', $student->initials());
        $this->assertCount(1, $student->qualifications);
        $this->assertSame('United Kingdom', $student->destinations->first()->destination);
    }

    public function test_document_status_and_scan_enums_cast(): void
    {
        $user = User::factory()->create();
        $student = Student::resolveFor($user);
        $type = DocumentType::where('key', 'passport')->firstOrFail();

        $file = DocumentFile::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'storage_key' => 'media/uuid.bin', 'original_name' => 'p.pdf',
            'mime' => 'application/pdf', 'size' => 1234, 'scan_status' => ScanStatus::Pending,
        ]);
        $doc = StudentDocument::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'status' => DocumentStatus::Uploaded, 'file_id' => $file->id, 'uploaded_at' => now(),
        ]);

        $this->assertInstanceOf(ScanStatus::class, $file->refresh()->scan_status);
        $this->assertFalse($file->isReadable());                 // pending → not readable
        $this->assertTrue($doc->status->isPresent());
        $this->assertFalse($doc->status->isLocked());
        $this->assertTrue(DocumentStatus::Verified->isLocked());
    }

    public function test_action_overdue_is_derived_from_due_date(): void
    {
        $student = Student::resolveFor(User::factory()->create());

        $overdue = new StudentAction(['due_at' => now()->subDay(), 'done' => false, 'title' => 'x']);
        $future = new StudentAction(['due_at' => now()->addDay(), 'done' => false, 'title' => 'y']);
        $noDate = new StudentAction(['due_at' => null, 'done' => false, 'title' => 'z']);
        $done = new StudentAction(['due_at' => now()->subDay(), 'done' => true, 'title' => 'w']);

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($future->isOverdue());
        $this->assertFalse($noDate->isOverdue());       // no date → never overdue
        $this->assertFalse($done->isOverdue());
    }

    public function test_access_log_is_append_only(): void
    {
        $user = User::factory()->create();
        $student = Student::resolveFor($user);
        $type = DocumentType::where('key', 'passport')->firstOrFail();
        $file = DocumentFile::create([
            'student_id' => $student->id, 'document_type_id' => $type->id, 'storage_key' => 'k',
            'original_name' => 'p.pdf', 'mime' => 'application/pdf', 'size' => 1, 'scan_status' => ScanStatus::Clean,
        ]);

        $row = DocumentAccessLog::record([
            'document_file_id' => $file->id, 'student_id' => $student->id,
            'actor_user_id' => $user->id, 'action' => 'download', 'ip' => '1.1.1.1',
        ]);

        $this->expectException(\RuntimeException::class);
        $row->update(['action' => 'tamper']);
    }
}
