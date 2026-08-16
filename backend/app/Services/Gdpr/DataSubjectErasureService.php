<?php

namespace App\Services\Gdpr;

use App\Enums\UserStatus;
use App\Models\AuthEvent;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\ContentAuditLog;
use App\Models\DataSubjectRequest;
use App\Models\DocumentDisclosure;
use App\Models\EmailVerificationCode;
use App\Models\Partner\Application;
use App\Models\PasswordResetToken;
use App\Models\StaffAccessLog;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\Student\Student;
use App\Models\Student\StudentAction;
use App\Models\Student\StudentAddress;
use App\Models\Student\StudentApplication;
use App\Models\Student\StudentDocument;
use App\Models\Student\StudentJourneyStage;
use App\Models\Student\StudentPreference;
use App\Models\Student\StudentPreferenceDestination;
use App\Models\Student\StudentProfile;
use App\Models\Student\StudentQualification;
use App\Models\Student\StudentTestScore;
use App\Models\Student\StudentTimelineEvent;
use App\Models\User;
use App\Services\DocumentStorage;
use App\Support\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 9B — the right-to-erasure path (GDPR Art. 17).
 *
 * Erasure is not "delete the person". Art. 17(3) says the right does not apply
 * where processing is necessary for a contract we are still performing or for a
 * legal obligation, and Art. 5(2) says we must be able to DEMONSTRATE that we
 * complied. Those two pull in opposite directions, so this service draws the
 * line in one place and writes down where it drew it:
 *
 *   PERSONAL DATA GOES — profile, address, qualifications, test scores,
 *   preferences, the portal tracking narrative, and the document bytes.
 *
 *   THE PROOF STAYS — document_files metadata, document_access_log,
 *   document_disclosures, staff_access_log, content_audit_log, auth_events and
 *   the terms acceptance. Proving you deleted something requires keeping the
 *   proof that it existed and that you destroyed it; a record that only says
 *   "nothing here" proves nothing.
 *
 *   THE LEGAL HOLD WINS — a student with a submitted application has a live
 *   contractual and regulatory relationship with VFI and the universities we
 *   filed with. That record cannot be erased on request. What we CAN still do,
 *   and do here, is pseudonymise the directly identifying fields (name, phone,
 *   email) so the retained record stops being a name-and-contact list while it
 *   remains a complete application record.
 *
 * Nothing in here ever hard-deletes an application, an audit row or an access
 * log row.
 */
class DataSubjectErasureService
{
    /** Only students are erasable here — see subject(). */
    private const SUBJECT_STUDENT = 'student';

    /** A reason short enough to be meaningless is the same as no reason. */
    private const MIN_REASON = 10;

    private const NAME_PLACEHOLDER = 'Erased';

    /**
     * Personal-data collections keyed by student_id. Deleted outright on an
     * unheld erasure: none of these is evidence of anything, they are simply the
     * person's own data.
     *
     * @var array<string, class-string<Model>>
     */
    private const PERSONAL_ROWS = [
        'student_profiles' => StudentProfile::class,
        'student_addresses' => StudentAddress::class,
        'student_preferences' => StudentPreference::class,
        'student_preference_destinations' => StudentPreferenceDestination::class,
        'student_qualifications' => StudentQualification::class,
        'student_test_scores' => StudentTestScore::class,
        'student_applications' => StudentApplication::class,
        'student_journey_stages' => StudentJourneyStage::class,
        'student_timeline_events' => StudentTimelineEvent::class,
        'student_actions' => StudentAction::class,
    ];

    /** Fields replaced with the irreversible placeholder, reported to the caller. */
    private const PSEUDONYMISED_FIELDS = [
        'students.first_name', 'students.middle_name', 'students.last_name',
        'students.email', 'students.phone', 'students.phone_cc',
        'student_profiles.first', 'student_profiles.middle', 'student_profiles.last',
        'student_profiles.email', 'student_profiles.phone', 'student_profiles.cc',
        'users.name', 'users.email', 'users.phone',
    ];

    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Report what WOULD happen, without changing anything.
     *
     * Counts only — the preview screen must never need to load a person's data
     * to tell an operator what erasing them would cost.
     */
    public function preview(string $subjectType, int $subjectId): array
    {
        $student = $this->subject($subjectType, $subjectId);
        $held = $this->heldApplications($student);

        $personal = $this->personalCounts($student);

        $blobs = DocumentFile::where('student_id', $student->id)
            ->whereNull('bytes_deleted_at')->count();

        $keep = $this->evidenceCounts($student, $held);

        // Under a hold nothing is deleted at all: the personal rows move from
        // the "would erase" column into the "would keep" column, so the operator
        // sees exactly what the hold is costing the data subject.
        return [
            'subject_type' => self::SUBJECT_STUDENT,
            'subject_id' => $student->id,
            'blocked' => $held > 0,
            'held_reason' => $held > 0 ? $this->holdReason($held) : null,
            'would_erase' => $held > 0 ? [] : $personal,
            'would_keep' => $held > 0 ? array_merge($keep, $personal) : $keep,
            'would_pseudonymise' => self::PSEUDONYMISED_FIELDS,
            'document_bytes' => $held > 0 ? 0 : $blobs,
        ];
    }

    /**
     * Perform the erasure. Throws RuntimeException when a legal hold blocks it.
     *
     * Call this OUTSIDE a transaction of your own: on a legal hold the register
     * row and the pseudonymisation are committed and then the exception is
     * thrown, and an enclosing transaction would roll the record of the refusal
     * back with it.
     */
    public function erase(string $subjectType, int $subjectId, User $actor, string $reason): DataSubjectRequest
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw new RuntimeException(
                'Record why this erasure is being run (at least '.self::MIN_REASON.' characters) — the register is read by a regulator, not by us.'
            );
        }

        $student = $this->subject($subjectType, $subjectId);
        $held = $this->heldApplications($student);

        // The register row is written FIRST and outside the transaction: a run
        // that crashes half way must still leave the request on the record. A
        // register that only lists the requests that finished is not a register.
        // subject_email is captured here on purpose — it is the only place the
        // address survives, and it is what lets us answer "did you action this
        // person's request" once every other copy is gone.
        $request = DataSubjectRequest::create([
            'type' => DataSubjectRequest::TYPE_ERASURE,
            'subject_type' => self::SUBJECT_STUDENT,
            'subject_id' => $student->id,
            'subject_email' => $student->email ?: $student->user?->email,
            'status' => DataSubjectRequest::STATUS_PENDING,
            'reason' => mb_substr($reason, 0, 2000),
            'requested_by_user_id' => $actor->id,
        ]);

        try {
            $summary = DB::transaction(fn () => $held > 0
                ? $this->pseudonymiseUnderHold($student, $held, $actor, $request)
                : $this->eraseEverything($student, $actor, $request));
        } catch (\Throwable $e) {
            // No detail from the exception goes into the register: the message
            // can carry field values, and this row is long-lived.
            $request->forceFill([
                'status' => DataSubjectRequest::STATUS_FAILED,
                'outcome' => 'The erasure did not complete ('.class_basename($e).'). Database changes were rolled back; '
                    .'any document bytes already destroyed cannot be restored, so re-run this request rather than assuming it did nothing.',
            ])->save();

            throw $e;
        }

        $request->forceFill([
            'status' => $held > 0 ? DataSubjectRequest::STATUS_BLOCKED : DataSubjectRequest::STATUS_COMPLETED,
            'outcome' => $summary['outcome'],
            'completed_at' => now(),
        ])->save();

        if ($held > 0) {
            // Same words in the register and in the exception, so the operator
            // on the screen and the auditor reading the row see one story.
            throw new RuntimeException($summary['outcome']);
        }

        return $request->refresh();
    }

    /* ------------------------------------------------------------------ */

    /** Deny-by-default: this path knows how to erase a student and nothing else. */
    private function subject(string $subjectType, int $subjectId): Student
    {
        // The subject type is never echoed back: it arrives from the caller and
        // an error string has a habit of ending up rendered on a page.
        if ($subjectType !== self::SUBJECT_STUDENT) {
            throw new RuntimeException('Only a student subject can be erased here.');
        }

        // Student carries no global scope, but drop them explicitly anyway so a
        // scope added later cannot silently turn this into a no-op erasure.
        $student = Student::withoutGlobalScopes()->with('user')->find($subjectId);
        if (! $student) {
            throw new RuntimeException('No such student.');
        }

        return $student;
    }

    /**
     * How many application records hold this person's data back.
     *
     * This read must never fail OPEN. On Postgres `applications` carries RLS
     * FORCE and its policy has no bypass clause, so a tenant-less read returns
     * zero rows — indistinguishable from "this person never applied", which
     * would erase a record we are contractually obliged to keep. Taking the read
     * inside the owning tenant is what makes the answer real.
     *
     * A student with no owning agency cannot have an application: the console
     * only creates one for a student it already owns (Student::forAgency in
     * PartnerApplicationController). If that ever changes, this has to loop the
     * owning tenants instead of trusting students.agency_id.
     */
    private function heldApplications(Student $student): int
    {
        $count = fn () => Application::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('student_id', $student->id)
            ->count();

        return $student->agency_id
            ? (int) TenantScope::runAs((int) $student->agency_id, $count)
            : (int) $count();
    }

    private function holdReason(int $held): string
    {
        return $held.' submitted application record(s) must be retained: VFI and the receiving institutions '
            .'are still party to them, and the applications are kept under contractual and regulatory retention '
            .'(GDPR Art. 17(3)(b),(e)). Erasure of those records is refused.';
    }

    /** Personal-data rows this student still has, non-zero counts only. */
    private function personalCounts(Student $student): array
    {
        $counts = [];
        foreach (self::PERSONAL_ROWS as $table => $model) {
            $n = $model::where('student_id', $student->id)->count();
            if ($n > 0) {
                $counts[$table] = $n;
            }
        }

        return $counts;
    }

    /** Evidence and held records that survive an erasure, non-zero counts only. */
    private function evidenceCounts(Student $student, int $held): array
    {
        $counts = [
            'applications' => $held,
            'document_files' => DocumentFile::where('student_id', $student->id)->count(),
            'student_documents' => StudentDocument::withTrashed()->where('student_id', $student->id)->count(),
            'document_access_log' => DocumentAccessLog::where('student_id', $student->id)->count(),
            'document_disclosures' => DocumentDisclosure::where('student_id', $student->id)->count(),
            'staff_access_log' => StaffAccessLog::where('subject_type', 'student')
                ->where('subject_id', $student->id)->count(),
        ];

        return array_filter($counts, fn (int $n) => $n > 0);
    }

    /* ------------------------------------------------------------------ */

    /** The unheld path: the personal data goes, the proof stays. */
    private function eraseEverything(Student $student, User $actor, DataSubjectRequest $request): array
    {
        $before = $this->personalCounts($student);

        $deleted = [];
        foreach (self::PERSONAL_ROWS as $table => $model) {
            // One bounded DELETE per collection — every one of these tables is
            // indexed on student_id and holds a handful of rows per person.
            $n = $model::where('student_id', $student->id)->delete();
            if ($n > 0) {
                $deleted[$table] = $n;
            }
        }

        $blobs = $this->destroyDocumentBytes($student);
        $this->pseudonymise($student);
        $credentials = $this->revokeCredentials($student);

        $kept = $this->evidenceCounts($student, 0);

        $outcome = 'Erased. Deleted '.array_sum($deleted).' personal row(s) across '.count($deleted).' table(s) '
            .'('.(implode(', ', array_keys($deleted)) ?: 'none present').'); destroyed the bytes of '.$blobs.' document file(s) '
            .'and stamped bytes_deleted_at on each; replaced name, phone and email on the student, profile and login '
            .'records with an irreversible placeholder and closed the login. '
            .'Retained as evidence of the deletion and of lawful processing: '
            .(implode(', ', array_keys($kept)) ?: 'no evidence rows existed').', plus content_audit_log, auth_events '
            .'and the terms acceptance.';

        $this->audit($student, $actor, $request, $before, [
            'status' => DataSubjectRequest::STATUS_COMPLETED,
            'deleted' => $deleted,
            'document_bytes_deleted' => $blobs,
            'credentials_revoked' => $credentials,
            'pseudonymised' => self::PSEUDONYMISED_FIELDS,
            'retained' => $kept,
        ]);

        return ['outcome' => $outcome];
    }

    /**
     * The held path: nothing is deleted, but the retained records stop being a
     * name-and-contact list. This is the deliberate middle ground — Art. 17(3)
     * lets us keep the application, it does not require us to keep the person's
     * phone number attached to it.
     */
    private function pseudonymiseUnderHold(Student $student, int $held, User $actor, DataSubjectRequest $request): array
    {
        $before = $this->personalCounts($student);

        $this->pseudonymise($student);
        $credentials = $this->revokeCredentials($student);

        $kept = $this->evidenceCounts($student, $held);

        $outcome = 'Blocked by a legal hold. '.$this->holdReason($held).' Nothing was deleted: '
            .implode(', ', array_keys($kept)).' were all kept, along with every audit row. '
            .'What was done: name, phone and email on the student, profile and login records were replaced with an '
            .'irreversible placeholder and the login was closed, so the retained records no longer carry direct '
            .'contact details. Ask again once the retention period on the application records has run out.';

        $this->audit($student, $actor, $request, $before, [
            'status' => DataSubjectRequest::STATUS_BLOCKED,
            'held_applications' => $held,
            'deleted' => [],
            'document_bytes_deleted' => 0,
            'credentials_revoked' => $credentials,
            'pseudonymised' => self::PSEUDONYMISED_FIELDS,
            'retained' => $kept,
        ]);

        return ['outcome' => $outcome];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Destroy the stored blobs, keep the rows.
     *
     * Chunked by id: a long-standing student can have dozens of files and this
     * also runs from the retention sweep, so the whole set is never in memory.
     * chunkById is required rather than chunk() because the filter column is the
     * one being written.
     *
     * The bytes are destroyed INSIDE the caller's transaction on purpose. A
     * rollback after a delete leaves a row that claims a file that is gone —
     * recoverable, because a re-run stamps the row and deleting a missing key is
     * a no-op. The other order (commit first, delete after) risks the bytes
     * outliving a completed erasure, which is the failure that actually harms
     * the data subject.
     */
    private function destroyDocumentBytes(Student $student): int
    {
        $destroyed = 0;

        DocumentFile::where('student_id', $student->id)
            ->whereNull('bytes_deleted_at')
            ->select(['id', 'storage_key'])
            ->chunkById(200, function ($files) use (&$destroyed) {
                foreach ($files as $file) {
                    $this->storage->delete((string) $file->storage_key);
                    $destroyed++;
                }

                // Metadata row KEPT, stamped with the moment the bytes died.
                DocumentFile::whereIn('id', $files->pluck('id')->all())
                    ->update(['bytes_deleted_at' => now()]);
            });

        return $destroyed;
    }

    /** Replace the directly identifying fields on every copy we hold. */
    private function pseudonymise(Student $student): void
    {
        $alias = $this->alias($student->id);

        $student->forceFill([
            'first_name' => self::NAME_PLACEHOLDER,
            'middle_name' => null,
            'last_name' => null,
            'email' => $alias,
            'phone' => null,
            'phone_cc' => null,
            // Drop the row out of the console lists as well — an erased subject
            // should not keep surfacing as a workable lead.
            'archived_at' => $student->archived_at ?? now(),
        ])->save();

        // A no-op on the unheld path (the row is already deleted); the whole
        // point of it on the held path.
        StudentProfile::where('student_id', $student->id)->update([
            'first' => self::NAME_PLACEHOLDER,
            'middle' => null,
            'last' => null,
            'email' => $alias,
            'phone' => null,
            'cc' => null,
        ]);

        if ($user = $student->user) {
            $user->forceFill([
                'name' => self::NAME_PLACEHOLDER,
                'email' => $alias,
                'phone' => null,
                // A closed account must not be signable-in: unusable password,
                // no second factor, no remembered session, suspended status.
                'password' => Str::random(64),      // hashed by the model cast
                'remember_token' => null,
                'mfa_secret' => null,
                'mfa_enrolled_at' => null,
                'email_verified_at' => null,
                'status' => UserStatus::Suspended->value,
            ])->save();
        }
    }

    /**
     * Kill everything that could still authenticate as, or resolve, the old
     * identity. These carry the person's email or a live credential and are not
     * evidence of anything — terms_acceptances IS evidence of consent and is
     * deliberately not touched.
     *
     * @return array<string,int>
     */
    private function revokeCredentials(Student $student): array
    {
        $user = $student->user;
        if (! $user) {
            return [];
        }

        $counts = [
            'password_reset_tokens' => PasswordResetToken::where('user_id', $user->id)->delete(),
            'email_verification_codes' => EmailVerificationCode::where('user_id', $user->id)->delete(),
            'personal_access_tokens' => DB::table('personal_access_tokens')
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)->delete(),
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->delete(),
        ];

        return array_filter($counts, fn (int $n) => $n > 0);
    }

    /**
     * A stable, irreversible placeholder. HMAC-keyed on APP_KEY rather than a
     * bare hash of the id: an unkeyed digest over a small integer space is
     * trivially reversible, and would let anyone holding the alias confirm which
     * student id was erased.
     */
    private function alias(int $subjectId): string
    {
        $digest = hash_hmac('sha1', self::SUBJECT_STUDENT.':'.$subjectId, (string) config('app.key'));

        return 'erased+'.substr($digest, 0, 16).'@erased.invalid';
    }

    /**
     * Audit the erasure itself.
     *
     * Counts and field NAMES only — never the values that were removed. An audit
     * row that quotes the erased name and email would keep a copy of exactly
     * what the data subject asked us to destroy, and this table is append-only,
     * so there would be no way back.
     */
    private function audit(Student $student, User $actor, DataSubjectRequest $request, array $before, array $after): void
    {
        ContentAuditLog::record(
            'gdpr_erasure',
            'student',
            (string) $student->id,
            ['personal_rows' => $before],
            $after + ['request_id' => $request->id, 'actor_user_id' => $actor->id],
        );

        // The login identity changed, and account-identity changes belong in the
        // auth log. No email on the row: the register already holds the one copy.
        if ($student->user) {
            AuthEvent::record('gdpr_erasure', [
                'user_id' => $student->user->id,
                'ip' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 255),
                'context' => ['request_id' => $request->id, 'status' => $after['status']],
            ]);
        }
    }
}
