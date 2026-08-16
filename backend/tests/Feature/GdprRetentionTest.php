<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Models\ContentAuditLog;
use App\Models\DocumentDisclosure;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\User;
use App\Services\DocumentStorage;
use App\Services\Gdpr\DisclosureService;
use App\Services\Gdpr\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 9B — the retention clock and the disclosure register.
 *
 * The load-bearing assertion in here is the one about what SURVIVES a purge: if
 * a future change starts deleting document_files rows or access-log lines along
 * with the bytes, VFI loses its proof that it deleted anything.
 */
class GdprRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** A stored blob with a real byte payload on the faked private disk. */
    private function file(string $bytes = 'passport-scan-bytes'): DocumentFile
    {
        $student = Student::resolveFor(User::factory()->create());
        $type = DocumentType::first() ?? DocumentType::create([
            'key' => 'passport', 'pack' => 'application', 'name' => 'Passport', 'position' => 1,
        ]);

        return DocumentFile::create([
            'student_id' => $student->id,
            'document_type_id' => $type->id,
            'storage_key' => app(DocumentStorage::class)->put($bytes),
            'original_name' => 'passport.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
            'scan_status' => ScanStatus::Clean->value,
        ]);
    }

    private function expired(): DocumentFile
    {
        $file = $this->file();
        $file->forceFill(['retention_until' => Carbon::yesterday()->toDateString()])->save();

        return $file;
    }

    private function retention(): RetentionService
    {
        return app(RetentionService::class);
    }

    public function test_set_clock_stores_the_date_and_audits_it(): void
    {
        $file = $this->file();
        $actor = User::factory()->create();
        $until = Carbon::now()->addYears(3);

        $this->retention()->setClock($file, $until, $actor);

        $this->assertDatabaseHas('document_files', [
            'id' => $file->id, 'retention_until' => $until->toDateString(),
        ]);

        $audit = ContentAuditLog::where('action', 'retention_clock')
            ->where('entity', 'document_file')->where('entity_id', (string) $file->id)->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->before['retention_until']);
        $this->assertSame($until->toDateString(), $audit->after['retention_until']);
        $this->assertSame($actor->id, $audit->after['actor_user_id']);
    }

    public function test_a_clock_cannot_be_set_on_bytes_that_are_already_gone(): void
    {
        $file = $this->expired();
        $this->retention()->purgeExpired();

        $this->expectException(\RuntimeException::class);
        $this->retention()->setClock($file->fresh(), Carbon::now()->addYear(), User::factory()->create());
    }

    public function test_apply_default_clocks_stamps_only_the_files_that_had_none(): void
    {
        $held = $this->file();
        $held->forceFill(['retention_until' => '2040-01-01'])->save();   // a legal hold
        $fresh = $this->file();

        $stamped = $this->retention()->applyDefaultClocks();

        $this->assertSame(1, $stamped);
        $this->assertSame('2040-01-01', $held->fresh()->retention_until);   // never moved
        $this->assertSame(
            Carbon::now()->addYears((int) config('documents.retention_years'))->toDateString(),
            $fresh->fresh()->retention_until,
        );

        // idempotent: a second sweep finds nothing left to do
        $this->assertSame(0, $this->retention()->applyDefaultClocks());
    }

    public function test_purge_destroys_the_bytes_but_keeps_the_row_and_the_evidence(): void
    {
        $file = $this->expired();
        DocumentAccessLog::record([
            'document_file_id' => $file->id, 'student_id' => $file->student_id, 'action' => 'upload',
        ]);

        $purged = $this->retention()->purgeExpired();

        $this->assertSame(1, $purged);
        Storage::disk('documents')->assertMissing($file->storage_key);
        $this->assertNotNull($file->fresh()->bytes_deleted_at);

        // the proof of deletion outlives the data
        $this->assertDatabaseHas('document_files', ['id' => $file->id, 'storage_key' => $file->storage_key]);
        $this->assertSame(1, DocumentAccessLog::where('document_file_id', $file->id)->where('action', 'upload')->count());
        $this->assertSame(1, DocumentAccessLog::where('document_file_id', $file->id)->where('action', 'purge')->count());

        // one audit row for the BATCH, carrying the count — not one per file
        $audits = ContentAuditLog::where('action', 'retention_purge')->get();
        $this->assertCount(1, $audits);
        $this->assertSame('document_files', $audits->first()->entity);
        $this->assertNull($audits->first()->entity_id);
        $this->assertSame(1, $audits->first()->after['purged']);
    }

    public function test_purge_leaves_a_live_clock_and_an_already_purged_file_alone(): void
    {
        $live = $this->file();
        $live->forceFill(['retention_until' => Carbon::tomorrow()->toDateString()])->save();

        $done = $this->file();
        $done->forceFill([
            'retention_until' => Carbon::yesterday()->toDateString(),
            'bytes_deleted_at' => Carbon::now()->subDay(),
        ])->save();
        $stampedAt = $done->fresh()->bytes_deleted_at;

        $this->assertSame(0, $this->retention()->purgeExpired());

        Storage::disk('documents')->assertExists($live->storage_key);
        $this->assertNull($live->fresh()->bytes_deleted_at);
        Storage::disk('documents')->assertExists($done->storage_key);
        $this->assertSame($stampedAt, $done->fresh()->bytes_deleted_at);   // not re-stamped
        $this->assertSame(0, ContentAuditLog::where('action', 'retention_purge')->count());
    }

    public function test_the_dry_run_flag_changes_nothing(): void
    {
        $file = $this->expired();

        $this->artisan('documents:purge-expired', ['--dry-run' => true])->assertExitCode(0);

        Storage::disk('documents')->assertExists($file->storage_key);
        $this->assertNull($file->fresh()->bytes_deleted_at);
        $this->assertSame(0, ContentAuditLog::where('action', 'retention_purge')->count());
        $this->assertSame(0, DocumentAccessLog::where('action', 'purge')->count());

        // and the same command without the flag really does purge
        $this->artisan('documents:purge-expired')->assertExitCode(0);
        Storage::disk('documents')->assertMissing($file->storage_key);
        $this->assertNotNull($file->fresh()->bytes_deleted_at);
    }

    public function test_a_disclosure_with_an_unknown_recipient_type_or_basis_is_refused(): void
    {
        $file = $this->file();
        $actor = User::factory()->create();

        try {
            app(DisclosureService::class)->record([
                'document_file_id' => $file->id, 'recipient_name' => 'Acme Recruiters',
                'recipient_type' => 'random_bloke', 'lawful_basis' => 'consent',
            ], $actor);
            $this->fail('An unknown recipient type should be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        try {
            app(DisclosureService::class)->record([
                'document_file_id' => $file->id, 'recipient_name' => 'Acme Recruiters',
                'recipient_type' => 'university', 'lawful_basis' => 'because_we_felt_like_it',
            ], $actor);
            $this->fail('An unknown lawful basis should be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        try {
            app(DisclosureService::class)->record([
                'document_file_id' => $file->id, 'recipient_name' => '   ',
                'recipient_type' => 'university', 'lawful_basis' => 'consent',
            ], $actor);
            $this->fail('A blank recipient name should be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, DocumentDisclosure::count());
    }

    public function test_a_valid_disclosure_is_recorded_and_audited(): void
    {
        $file = $this->file();
        $actor = User::factory()->create();

        $disclosure = app(DisclosureService::class)->record([
            'document_file_id' => $file->id,
            'recipient_name' => 'University of Leeds',
            'recipient_type' => 'university',
            'lawful_basis' => 'contract',
            'note' => 'Transcript sent with the application pack.',
        ], $actor);

        $this->assertSame($file->student_id, $disclosure->student_id);   // taken from the file
        $this->assertSame($actor->id, $disclosure->disclosed_by_user_id);
        $this->assertNotNull($disclosure->disclosed_at);                 // defaults to now
        $this->assertNotNull($disclosure->created_at);

        $audit = ContentAuditLog::where('action', 'document_disclosure')->first();
        $this->assertNotNull($audit);
        $this->assertSame('document_file', $audit->entity);
        $this->assertSame((string) $file->id, $audit->entity_id);
        $this->assertSame($disclosure->id, $audit->after['disclosure_id']);
        $this->assertSame('contract', $audit->after['lawful_basis']);
    }
}
