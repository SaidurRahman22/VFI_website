<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\UserStatus;
use App\Models\AuthEvent;
use App\Models\ContentAuditLog;
use App\Models\DataSubjectRequest;
use App\Models\EmailVerificationCode;
use App\Models\Partner\Application;
use App\Models\Partner\PartnerAgency;
use App\Models\PasswordResetToken;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\DocumentType;
use App\Models\Student\Student;
use App\Models\Student\StudentAddress;
use App\Models\Student\StudentPreference;
use App\Models\Student\StudentProfile;
use App\Models\Student\StudentQualification;
use App\Models\Student\StudentTestScore;
use App\Models\Student\StudentTimelineEvent;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\DocumentStorage;
use App\Services\Gdpr\DataSubjectErasureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9B — right to erasure. Two things have to be true at once: the personal
 * data is really gone, and the proof that it was destroyed is really kept. The
 * legal hold is the third: a submitted application is not erasable on request.
 */
class GdprErasureTest extends TestCase
{
    use RefreshDatabase;

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

    private function svc(): DataSubjectErasureService
    {
        return app(DataSubjectErasureService::class);
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    /** A portal student with a login and a full set of personal rows. */
    private function student(?int $agencyId = null): Student
    {
        $user = User::factory()->create([
            'name' => 'Rahim Uddin',
            'email' => 'rahim'.uniqid().'@example.test',
            'password' => 'correct-horse-battery',
        ]);
        $user->forceFill(['phone' => '+8801711000000'])->save();

        $student = Student::resolveFor($user);
        $student->forceFill([
            'agency_id' => $agencyId,
            'first_name' => 'Rahim',
            'middle_name' => 'A',
            'last_name' => 'Uddin',
            'phone_cc' => '+880',
            'phone' => '1711000000',
        ])->save();

        StudentProfile::create([
            'student_id' => $student->id, 'first' => 'Rahim', 'last' => 'Uddin',
            'dob' => '2001-04-02', 'nationality' => 'Bangladeshi',
            'cc' => '+880', 'phone' => '1711000000', 'email' => $user->email,
        ]);
        StudentAddress::create([
            'student_id' => $student->id, 'line1' => '12 Green Road', 'city' => 'Dhaka', 'country' => 'Bangladesh',
        ]);
        StudentPreference::create(['student_id' => $student->id, 'intake' => 'Sep 2026', 'field' => 'Computing']);
        StudentQualification::create(['student_id' => $student->id, 'qualification' => 'HSC', 'year' => '2019']);
        StudentTestScore::create(['student_id' => $student->id, 'test' => 'IELTS', 'score_raw' => '7.5']);
        StudentTimelineEvent::create([
            'student_id' => $student->id, 'occurred_on' => now()->toDateString(),
            'tone' => 'info', 'title' => 'Account created', 'body' => 'Welcome Rahim.',
        ]);

        return $student->refresh();
    }

    /** A stored blob + its metadata row + one access-log entry. */
    private function documentFor(Student $student): DocumentFile
    {
        $type = DocumentType::first() ?? DocumentType::create([
            'key' => 'passport', 'pack' => 'application', 'name' => 'Passport', 'position' => 1,
        ]);

        $key = app(DocumentStorage::class)->put('%PDF-1.4 passport scan');

        $file = DocumentFile::create([
            'student_id' => $student->id, 'document_type_id' => $type->id,
            'storage_key' => $key, 'original_name' => 'passport.pdf',
            'mime' => 'application/pdf', 'size' => 21, 'scan_status' => ScanStatus::Clean->value,
        ]);

        DocumentAccessLog::record([
            'document_file_id' => $file->id, 'student_id' => $student->id,
            'actor_user_id' => null, 'action' => 'upload',
        ]);

        return $file;
    }

    /** A student owned by an agency, with one submitted application. */
    private function studentWithApplication(): array
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme Education', 'country' => 'Bangladesh']);
        $student = $this->student($agency->id);

        app(TenantContext::class)->setAgencyId($agency->id);
        $app = Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'status' => 'submitted', 'ack_no' => 'ACK-1001', 'submitted_at' => now(),
        ]);
        app(TenantContext::class)->clear();

        return [$student, $app];
    }

    /* ---------------------------------------------------------------- */

    public function test_a_student_with_no_applications_is_erased(): void
    {
        $student = $this->student();
        $user = $student->user;
        $oldEmail = $user->email;

        $request = $this->svc()->erase('student', $student->id, $this->staff(), 'Erasure request received by email on 16 Aug, identity confirmed.');

        // the personal-data collections are gone
        $this->assertSame(0, StudentProfile::where('student_id', $student->id)->count());
        $this->assertSame(0, StudentAddress::where('student_id', $student->id)->count());
        $this->assertSame(0, StudentPreference::where('student_id', $student->id)->count());
        $this->assertSame(0, StudentQualification::where('student_id', $student->id)->count());
        $this->assertSame(0, StudentTestScore::where('student_id', $student->id)->count());
        $this->assertSame(0, StudentTimelineEvent::where('student_id', $student->id)->count());

        // the anchor row survives, stripped of anything identifying
        $student->refresh();
        $this->assertMatchesRegularExpression('/^erased\+[0-9a-f]{16}@erased\.invalid$/', $student->email);
        $this->assertSame('Erased', $student->first_name);
        $this->assertNull($student->last_name);
        $this->assertNull($student->middle_name);
        $this->assertNull($student->phone);
        $this->assertNull($student->phone_cc);

        // and so does the login — same placeholder, and no longer usable
        $user->refresh();
        $this->assertSame($student->email, $user->email);
        $this->assertSame('Erased', $user->name);
        $this->assertNull($user->phone);
        $this->assertSame(UserStatus::Suspended, $user->status);
        $this->assertFalse(Hash::check('correct-horse-battery', $user->password));

        // the register says what happened
        $this->assertSame(DataSubjectRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(DataSubjectRequest::TYPE_ERASURE, $request->type);
        $this->assertNotNull($request->completed_at);
        $this->assertStringContainsString('Erased.', $request->outcome);
        $this->assertStringContainsString('student_profiles', $request->outcome);
        // the address is deliberately kept on the register — it is the only copy
        // left, and it is what answers "did you action this person's request".
        $this->assertSame($oldEmail, $request->subject_email);
    }

    public function test_the_placeholder_is_stable_and_not_a_bare_hash_of_the_id(): void
    {
        $student = $this->student();
        $this->svc()->erase('student', $student->id, $this->staff(), 'Verified erasure request, ticket 8891.');

        $alias = $student->refresh()->email;

        // stable: derived from the subject, not from a random value
        $this->assertSame($alias, $student->user->refresh()->email);
        // and not reversible by hashing the id — the digest is keyed
        $this->assertStringNotContainsString(substr(sha1('student:'.$student->id), 0, 16), $alias);
    }

    public function test_document_bytes_are_destroyed_but_the_metadata_and_logs_survive(): void
    {
        $student = $this->student();
        $file = $this->documentFor($student);

        Storage::disk('documents')->assertExists($file->storage_key);

        $this->svc()->erase('student', $student->id, $this->staff(), 'Erasure request confirmed by phone, ref 4410.');

        // bytes gone
        Storage::disk('documents')->assertMissing($file->storage_key);

        // metadata row KEPT and stamped: that stamp is the proof of deletion
        $this->assertDatabaseHas('document_files', ['id' => $file->id, 'storage_key' => $file->storage_key]);
        $this->assertNotNull(DocumentFile::find($file->id)->bytes_deleted_at);

        // the access log is evidence too, and is never touched
        $this->assertSame(1, DocumentAccessLog::where('document_file_id', $file->id)->count());
    }

    public function test_erasing_twice_does_not_re_delete_bytes_or_break(): void
    {
        $student = $this->student();
        $file = $this->documentFor($student);
        $staff = $this->staff();

        $this->svc()->erase('student', $student->id, $staff, 'First erasure run, ticket 5150.');
        $stamp = DocumentFile::find($file->id)->bytes_deleted_at;

        $second = $this->svc()->erase('student', $student->id, $staff, 'Re-run after an operator refreshed the page.');

        $this->assertSame(DataSubjectRequest::STATUS_COMPLETED, $second->status);
        // already stamped, so it is skipped rather than re-stamped
        $this->assertSame($stamp, DocumentFile::find($file->id)->bytes_deleted_at);
        $this->assertSame(2, DataSubjectRequest::where('subject_id', $student->id)->count());
    }

    public function test_a_student_with_an_application_is_blocked_and_the_record_survives(): void
    {
        [$student, $app] = $this->studentWithApplication();
        $before = $app->only(['agency_id', 'student_id', 'status', 'ack_no']);

        try {
            $this->svc()->erase('student', $student->id, $this->staff(), 'Erasure request received, checking whether we may action it.');
            $this->fail('a held student must not be erasable');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('legal hold', $e->getMessage());
            $this->assertStringContainsString('retention', $e->getMessage());
        }

        // the application is untouched
        $fresh = Application::withoutGlobalScopes()->find($app->id);
        $this->assertNotNull($fresh);
        $this->assertSame($before, $fresh->only(['agency_id', 'student_id', 'status', 'ack_no']));

        // nothing personal was deleted
        $this->assertSame(1, StudentProfile::where('student_id', $student->id)->count());
        $this->assertSame(1, StudentQualification::where('student_id', $student->id)->count());

        // but the direct identifiers were replaced everywhere they are held
        $student->refresh();
        $this->assertMatchesRegularExpression('/@erased\.invalid$/', $student->email);
        $profile = StudentProfile::where('student_id', $student->id)->first();
        $this->assertSame('Erased', $profile->first);
        $this->assertNull($profile->last);
        $this->assertNull($profile->phone);
        $this->assertSame($student->email, $profile->email);
        // dob and nationality are NOT direct identifiers and stay with the held record
        $this->assertSame('2001-04-02', $profile->dob->toDateString());

        // and the refusal is on the register, in words a regulator can read
        $request = DataSubjectRequest::where('subject_id', $student->id)->latest('id')->firstOrFail();
        $this->assertSame(DataSubjectRequest::STATUS_BLOCKED, $request->status);
        $this->assertStringContainsString('Blocked by a legal hold', $request->outcome);
        $this->assertStringContainsString('application record(s) must be retained', $request->outcome);
        $this->assertStringContainsString('Nothing was deleted', $request->outcome);
    }

    public function test_preview_reports_the_hold_without_changing_anything(): void
    {
        [$student] = $this->studentWithApplication();

        $preview = $this->svc()->preview('student', $student->id);

        $this->assertTrue($preview['blocked']);
        $this->assertNotNull($preview['held_reason']);
        $this->assertSame([], $preview['would_erase']);
        $this->assertSame(1, $preview['would_keep']['applications']);
        $this->assertSame(1, $preview['would_keep']['student_profiles']);
        $this->assertSame(0, $preview['document_bytes']);

        // preview is a read: the student is exactly as it was
        $this->assertSame(1, StudentProfile::where('student_id', $student->id)->count());
        $this->assertSame('Rahim', $student->refresh()->first_name);
        $this->assertSame(0, DataSubjectRequest::count());
    }

    public function test_preview_of_an_unheld_student_lists_the_rows_and_the_blobs(): void
    {
        $student = $this->student();
        $this->documentFor($student);

        $preview = $this->svc()->preview('student', $student->id);

        $this->assertFalse($preview['blocked']);
        $this->assertNull($preview['held_reason']);
        $this->assertSame(1, $preview['would_erase']['student_profiles']);
        $this->assertSame(1, $preview['would_erase']['student_qualifications']);
        $this->assertArrayNotHasKey('applications', $preview['would_keep']);
        $this->assertSame(1, $preview['would_keep']['document_files']);
        $this->assertSame(1, $preview['would_keep']['document_access_log']);
        $this->assertSame(1, $preview['document_bytes']);
    }

    /**
     * The hold read is taken inside the owning tenant (Postgres RLS would
     * otherwise hide `applications` and the check would fail OPEN). Prove an
     * agency-owned student with no application is still erasable.
     */
    public function test_an_agency_owned_student_with_no_application_is_still_erased(): void
    {
        $agency = PartnerAgency::create(['legal_name' => 'Beta Consultants', 'country' => 'Bangladesh']);
        $student = $this->student($agency->id);

        $request = $this->svc()->erase('student', $student->id, $this->staff(), 'Lead withdrew consent, no application was ever filed.');

        $this->assertSame(DataSubjectRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(0, StudentProfile::where('student_id', $student->id)->count());
        $this->assertSame($agency->id, $student->refresh()->agency_id);
    }

    /** An erased identity must not leave a live way back into the account. */
    public function test_credentials_and_pending_tokens_are_revoked(): void
    {
        $student = $this->student();
        $user = $student->user;

        PasswordResetToken::create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', 'raw-token'),
            'requested_for_email' => $user->email, 'expires_at' => now()->addHour(),
        ]);
        EmailVerificationCode::create([
            'flow_id' => (string) Str::uuid(), 'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
        ]);
        $user->createToken('portal');
        TermsAcceptance::create([
            'user_id' => $user->id, 'document' => 'terms', 'version' => '1', 'accepted_at' => now(),
        ]);

        $this->svc()->erase('student', $student->id, $this->staff(), 'Erasure request confirmed in writing, ticket 3312.');

        $this->assertSame(0, PasswordResetToken::where('user_id', $user->id)->count());
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->count());
        $this->assertSame(0, $user->tokens()->count());

        // consent evidence is NOT a credential: it proves we were allowed to
        // process in the first place, so it stays.
        $this->assertSame(1, TermsAcceptance::where('user_id', $user->id)->count());
    }

    /** Agency-registered leads have no login at all — the path must not assume one. */
    public function test_a_lead_with_no_login_can_be_erased(): void
    {
        $agency = PartnerAgency::create(['legal_name' => 'Gamma Advisors', 'country' => 'Bangladesh']);
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal', 'student_ref' => 'R-'.uniqid(),
            'email' => 'lead@example.test', 'first_name' => 'Lead', 'last_name' => 'Person', 'phone' => '1799000000',
        ]);
        StudentProfile::create(['student_id' => $student->id, 'first' => 'Lead', 'last' => 'Person']);

        $request = $this->svc()->erase('student', $student->id, $this->staff(), 'Lead asked to be removed from our records.');

        $this->assertSame(DataSubjectRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame('lead@example.test', $request->subject_email);
        $this->assertSame(0, StudentProfile::where('student_id', $student->id)->count());
        $this->assertMatchesRegularExpression('/@erased\.invalid$/', $student->refresh()->email);
        $this->assertNull($student->phone);
    }

    public function test_the_account_identity_change_is_recorded_in_the_auth_log_without_the_address(): void
    {
        $student = $this->student();
        $oldEmail = $student->user->email;

        $request = $this->svc()->erase('student', $student->id, $this->staff(), 'Erasure request verified, ticket 9001.');

        $event = AuthEvent::where('event', 'gdpr_erasure')->where('user_id', $student->user->id)->firstOrFail();
        $this->assertSame($request->id, $event->context['request_id']);
        $this->assertSame(DataSubjectRequest::STATUS_COMPLETED, $event->context['status']);
        $this->assertNull($event->email);
        $this->assertStringNotContainsString($oldEmail, json_encode($event->context));
    }

    public function test_a_blank_or_trivial_reason_is_refused_and_nothing_happens(): void
    {
        $student = $this->student();
        $staff = $this->staff();

        foreach (['', '   ', 'gdpr'] as $reason) {
            try {
                $this->svc()->erase('student', $student->id, $staff, $reason);
                $this->fail('a reason of "'.$reason.'" must be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('at least', $e->getMessage());
            }
        }

        $this->assertSame(0, DataSubjectRequest::count());
        $this->assertSame(1, StudentProfile::where('student_id', $student->id)->count());
        $this->assertSame('Rahim', $student->refresh()->first_name);
    }

    /**
     * A run that dies half way must leave the request on the register as failed
     * and must not half-erase the person. A register that only lists the runs
     * that finished is not a register.
     */
    public function test_a_failed_run_rolls_back_and_is_recorded_as_failed(): void
    {
        $student = $this->student();
        $this->documentFor($student);

        $this->app->bind(DocumentStorage::class, fn () => new class extends DocumentStorage
        {
            public function delete(string $key): void
            {
                throw new \RuntimeException('blob store unreachable');
            }
        });

        try {
            $this->svc()->erase('student', $student->id, $this->staff(), 'Erasure request, storage happens to be down.');
            $this->fail('a storage failure must not report success');
        } catch (\RuntimeException) {
            // expected
        }

        // the deletes are rolled back — no half-erased person
        $this->assertSame(1, StudentProfile::where('student_id', $student->id)->count());
        $this->assertSame('Rahim', $student->refresh()->first_name);

        $request = DataSubjectRequest::where('subject_id', $student->id)->latest('id')->firstOrFail();
        $this->assertSame(DataSubjectRequest::STATUS_FAILED, $request->status);
        $this->assertStringContainsString('did not complete', $request->outcome);
        $this->assertNull($request->completed_at);
        // the raw exception text never lands on a long-lived row
        $this->assertStringNotContainsString('blob store unreachable', $request->outcome);
    }

    public function test_only_a_student_subject_can_be_erased_here(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->preview('user', 1);
    }

    public function test_an_unknown_subject_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->erase('student', 999999, $this->staff(), 'Erasure request for an id that does not exist.');
    }

    public function test_the_audit_trail_is_written_and_is_not_itself_erased(): void
    {
        $student = $this->student();
        $this->documentFor($student);

        // an audit row that predates the erasure — history must survive it
        ContentAuditLog::record('verify', 'student_document', (string) $student->id, ['status' => 'uploaded'], ['status' => 'verified']);
        $existing = ContentAuditLog::where('action', 'verify')->count();

        $staff = $this->staff();
        $request = $this->svc()->erase('student', $student->id, $staff, 'Erasure request verified against passport, ticket 7720.');

        $this->assertSame($existing, ContentAuditLog::where('action', 'verify')->count());

        $audit = ContentAuditLog::where('action', 'gdpr_erasure')
            ->where('entity', 'student')->where('entity_id', (string) $student->id)->latest('id')->firstOrFail();

        $this->assertSame(1, $audit->before['personal_rows']['student_profiles']);
        $this->assertSame(1, $audit->after['deleted']['student_profiles']);
        $this->assertSame(1, $audit->after['document_bytes_deleted']);
        $this->assertSame($staff->id, $audit->after['actor_user_id']);
        $this->assertSame($request->id, $audit->after['request_id']);

        // the audit must not carry a copy of what it just erased
        $json = json_encode([$audit->before, $audit->after]);
        $this->assertStringNotContainsString('Rahim', $json);
        $this->assertStringNotContainsString('1711000000', $json);
    }

    public function test_a_blocked_erasure_is_also_audited(): void
    {
        [$student] = $this->studentWithApplication();

        try {
            $this->svc()->erase('student', $student->id, $this->staff(), 'Erasure request received, checking the hold.');
        } catch (\RuntimeException) {
            // expected — the register and the audit must still be there
        }

        $audit = ContentAuditLog::where('action', 'gdpr_erasure')->latest('id')->firstOrFail();
        $this->assertSame(DataSubjectRequest::STATUS_BLOCKED, $audit->after['status']);
        $this->assertSame(1, $audit->after['held_applications']);
        $this->assertSame([], $audit->after['deleted']);
    }
}
