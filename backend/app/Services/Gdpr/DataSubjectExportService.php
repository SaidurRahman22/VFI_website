<?php

namespace App\Services\Gdpr;

use App\Models\AuthEvent;
use App\Models\Concerns\BelongsToAgencyScope;
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
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Phase 9B — the subject access request: everything VFI holds about one person,
 * assembled into a single file.
 *
 * Three rules shape this class:
 *
 *   1. The register row is written BEFORE the work, never after. A request that
 *      blew up half way is still a request that was made, and the regulator's
 *      question is "what happened to it", not "did it succeed".
 *   2. The bundle is metadata-complete and content-free where content is a
 *      liability: a document is described (name, type, size, checksum, retention
 *      clock) but its bytes are never copied into a second place. Neither are
 *      credentials, at any strength.
 *   3. Every section is bounded. A subject with years of history must produce a
 *      large file, not an out-of-memory process — so each section streams and
 *      each section has a hard row cap that the bundle itself declares.
 *
 * Read-only apart from the register row: an export never deletes or edits the
 * data it describes.
 */
class DataSubjectExportService
{
    public const SUBJECT_STUDENT = 'student';

    public const SUBJECT_USER = 'user';

    /**
     * Hard row cap per section. Chosen to be far above any real person's history
     * while still bounding the worst case — a scripted or abusive account can
     * generate log rows without limit, and an export must not become the way to
     * turn that into an outage.
     */
    private const SECTION_CAP = 5000;

    /** Never exported at any strength — a leaked hash is a leaked password, eventually. */
    private const NEVER_EXPORT = ['password', 'remember_token', 'mfa_secret'];

    /**
     * Sections where the cap bit, keyed by name. Instance state because it is
     * filled deep in the build and surfaced in meta at the top; reset on entry to
     * export() so one call can never report another call's truncation.
     *
     * @var array<string,true>
     */
    private array $truncated = [];

    /**
     * Build the complete record VFI holds about one data subject.
     *
     * @param  string  $subjectType  'student' or 'user'
     */
    public function export(string $subjectType, int $subjectId, User $actor, ?string $reason = null): DataSubjectRequest
    {
        $subjectType = mb_strtolower(trim($subjectType));
        if (! in_array($subjectType, [self::SUBJECT_STUDENT, self::SUBJECT_USER], true)) {
            throw new RuntimeException('A data subject is either a student or a user.');
        }
        if ($subjectId < 1) {
            throw new RuntimeException('A data subject id is required.');
        }

        $this->truncated = [];

        // The register row comes first, deliberately. If the build below dies,
        // "you asked us on the 3rd and it failed" is still on the record.
        $request = DataSubjectRequest::create([
            'type' => DataSubjectRequest::TYPE_EXPORT,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_email' => $this->subjectEmail($subjectType, $subjectId),
            'status' => DataSubjectRequest::STATUS_PENDING,
            'reason' => filled($reason) ? mb_substr(trim($reason), 0, 1000) : null,
            'requested_by_user_id' => $actor->id,
        ]);

        try {
            $sections = $subjectType === self::SUBJECT_STUDENT
                ? $this->studentBundle($this->findStudent($subjectId))
                : $this->userBundle($this->findUser($subjectId));

            $counts = $this->sectionCounts($sections);
            $bundle = array_merge(['meta' => $this->meta($request, $actor, $subjectType, $subjectId)], $sections);

            // Substitute rather than throw on a stray non-UTF-8 byte in stored
            // free text: a bad byte from years ago must not deny someone their data.
            $json = json_encode(
                $bundle,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );

            // Private disk only. This file is the whole person in one place — it
            // must never be reachable by URL, only by an authorised, audited read.
            $path = 'gdpr/exports/'.Str::uuid()->toString().'.json';
            if (Storage::disk('local')->put($path, $json) === false) {
                throw new RuntimeException('The export bundle could not be written to private storage.');
            }

            $request->forceFill([
                'status' => DataSubjectRequest::STATUS_COMPLETED,
                'artifact_path' => $path,
                'outcome' => $this->outcomeNote($counts),
                'completed_at' => now(),
            ])->save();

            // Counts and shape only: the audit trail says an export happened and
            // how big it was, and is not a second copy of the person's data.
            ContentAuditLog::record('gdpr_export', 'data_subject', (string) $subjectId, null, [
                'request_id' => $request->id,
                'subject_type' => $subjectType,
                'artifact_path' => $path,
                'artifact_bytes' => strlen($json),
                'sections' => $counts,
                'truncated_sections' => array_keys($this->truncated),
            ]);

            return $request;
        } catch (Throwable $e) {
            $request->forceFill([
                'status' => DataSubjectRequest::STATUS_FAILED,
                'outcome' => $this->failureNote($e),
            ])->save();

            throw $e;
        }
    }

    // ---------------------------------------------------------------- subject

    /**
     * Captured onto the register row before the work, so the register still
     * identifies the request after the subject's own rows are erased.
     */
    private function subjectEmail(string $subjectType, int $subjectId): ?string
    {
        if ($subjectType === self::SUBJECT_USER) {
            return User::withTrashed()->whereKey($subjectId)->value('email');
        }

        $student = Student::query()->select(['id', 'email', 'user_id'])->find($subjectId);
        if (! $student) {
            return null;
        }

        return $student->email
            ?: ($student->user_id ? User::withTrashed()->whereKey($student->user_id)->value('email') : null);
    }

    /**
     * Students carry no global scope (isolation is call-site ->forAgency()), so a
     * plain find genuinely reaches the row whichever agency owns it. That reach
     * is the point here: a subject access request is answered for the person, not
     * for a tenant.
     */
    private function findStudent(int $id): Student
    {
        return Student::query()->find($id) ?? throw new RuntimeException('No such student.');
    }

    /** Soft-deleted accounts still hold data, so they are still exportable. */
    private function findUser(int $id): User
    {
        return User::withTrashed()->find($id) ?? throw new RuntimeException('No such user.');
    }

    // ---------------------------------------------------------------- bundles

    /** @return array<string,mixed> */
    private function studentBundle(Student $student): array
    {
        // Seeded reference table, one row per document type and a handful in
        // total: loading it once is what keeps a per-row lookup out of the loops.
        $typeKeys = DocumentType::query()->pluck('key', 'id')->all();

        $sections = [
            'student' => $student->toArray(),
            'owning_agency' => $this->agencySummary($student->agency_id),
            'profile' => $student->profile()->first()?->toArray(),
            'address' => $student->address()->first()?->toArray(),
            'preference' => $student->preference()->first()?->toArray(),

            'preferred_destinations' => $this->rows(
                'preferred_destinations', $student->destinations(), fn ($r) => $r->toArray(),
            ),
            'qualifications' => $this->rows(
                'qualifications', $student->qualifications(), fn ($r) => $r->toArray(),
            ),
            'test_scores' => $this->rows(
                'test_scores', $student->testScores(), fn ($r) => $r->toArray(),
            ),
            'journey_stages' => $this->rows(
                'journey_stages', $student->journeyStages(), fn ($r) => $r->toArray(),
            ),
            'timeline' => $this->rows(
                'timeline', $student->timeline(), fn ($r) => $r->toArray(),
            ),
            'actions' => $this->rows(
                'actions', $student->actions(), fn ($r) => $r->toArray(),
            ),
            'portal_applications' => $this->rows(
                'portal_applications', $student->applications(), fn ($r) => $r->toArray(),
            ),

            // Soft-deleted checklist rows are still held, so they are still theirs.
            'document_checklist' => $this->rows(
                'document_checklist',
                StudentDocument::withTrashed()->where('student_id', $student->id)->orderBy('id'),
                fn (StudentDocument $d) => [
                    'id' => $d->id,
                    'document_type' => $typeKeys[$d->document_type_id] ?? null,
                    'status' => $d->status?->value,
                    'file_id' => $d->file_id,
                    'uploaded_at' => $this->iso($d->uploaded_at),
                    'verified_by_user_id' => $d->verified_by,
                    'verified_at' => $this->iso($d->verified_at),
                    'rejection_reason' => $d->rejection_reason,
                    'deleted_at' => $this->iso($d->deleted_at),
                ],
            ),

            // Metadata only. The bytes stay in the blob store: copying a passport
            // scan into a second file is how one breach becomes two.
            'document_files' => $this->rows(
                'document_files',
                DocumentFile::query()->where('student_id', $student->id)->orderBy('id'),
                fn (DocumentFile $f) => [
                    'id' => $f->id,
                    'document_type' => $typeKeys[$f->document_type_id] ?? null,
                    'original_name' => $f->original_name,
                    'mime' => $f->mime,
                    'size_bytes' => $f->size,
                    'sha256' => $f->sha256,
                    'scan_status' => $f->scan_status?->value,
                    'retention_until' => $this->iso($f->retention_until),
                    'bytes_deleted_at' => $this->iso($f->bytes_deleted_at),
                    'uploaded_at' => $this->iso($f->created_at),
                ],
            ),

            // Who OUTSIDE VFI received a document — the part of the record a
            // subject is actually entitled to be told about.
            'document_disclosures' => $this->rows(
                'document_disclosures',
                DocumentDisclosure::query()->where('student_id', $student->id)->orderByDesc('id'),
                fn (DocumentDisclosure $d) => [
                    'id' => $d->id,
                    'document_file_id' => $d->document_file_id,
                    'recipient_name' => $d->recipient_name,
                    'recipient_type' => $d->recipient_type,
                    'lawful_basis' => $d->lawful_basis,
                    'note' => $d->note,
                    'disclosed_by_user_id' => $d->disclosed_by_user_id,
                    'disclosed_at' => $this->iso($d->disclosed_at),
                ],
            ),

            // Reads of their files. The requester's IP and device are held back:
            // on a staff read that is the staff member's own data, not the
            // subject's, and it tells the subject nothing they asked for.
            'document_access_log' => $this->rows(
                'document_access_log',
                DocumentAccessLog::query()->where('student_id', $student->id)->orderByDesc('id'),
                fn (DocumentAccessLog $l) => [
                    'id' => $l->id,
                    'document_file_id' => $l->document_file_id,
                    'action' => $l->action,
                    'actor_user_id' => $l->actor_user_id,
                    'created_at' => $this->iso($l->created_at),
                ],
            ),

            // Who at VFI opened this person's record from outside their agency,
            // and the reason they gave. Answering that is the whole purpose of
            // the log, so the identity and the reason are both included.
            'staff_access_log' => $this->rows(
                'staff_access_log',
                StaffAccessLog::query()
                    ->where('subject_type', 'student')->where('subject_id', $student->id)
                    ->orderByDesc('id'),
                fn (StaffAccessLog $l) => [
                    'id' => $l->id,
                    'actor_email' => $l->actor_email,
                    'reason' => $l->reason,
                    'subject_agency_id' => $l->subject_agency_id,
                    'created_at' => $this->iso($l->created_at),
                ],
            ),
        ];

        return array_merge($sections, $this->pipelineSections($student));
    }

    /** @return array<string,mixed> */
    private function userBundle(User $user): array
    {
        $sections = [
            // toArray() already drops the credentials (the model marks them
            // hidden) — stripping them again by name means this export does not
            // depend on that staying true in a model someone edits later.
            'user' => Arr::except($user->toArray(), self::NEVER_EXPORT),

            'roles' => $this->rows(
                'roles',
                UserRole::query()->where('user_id', $user->id)->orderBy('id'),
                fn (UserRole $r) => [
                    'id' => $r->id,
                    'role' => $r->role?->value,
                    'agency_id' => $r->agency_id,
                    'granted_at' => $this->iso($r->granted_at),
                    'revoked_at' => $this->iso($r->revoked_at),
                ],
            ),

            'auth_events' => $this->rows(
                'auth_events',
                AuthEvent::query()->where(function (Builder $q) use ($user) {
                    $q->where('user_id', $user->id);
                    // Pre-authentication events (a failed sign-in, a reset for an
                    // address with no account) carry the email and no user id.
                    if (filled($user->email)) {
                        $q->orWhere('email', $user->email);
                    }
                })->orderByDesc('id'),
                fn (AuthEvent $e) => [
                    'id' => $e->id,
                    'event' => $e->event,
                    'ip' => $e->ip,
                    'user_agent' => $e->user_agent,
                    'context' => $e->context,
                    'created_at' => $this->iso($e->created_at),
                ],
            ),
        ];

        // A login and a student record are the same person; one request has to
        // answer for both, or the subject has to ask twice for half an answer.
        $student = Student::query()->where('user_id', $user->id)->first();
        if ($student) {
            $sections['student_record'] = $this->studentBundle($student);
        }

        return $sections;
    }

    /**
     * The partner pipeline sits behind BOTH tenancy nets: the fail-closed
     * Eloquent scope AND a Postgres RLS policy whose USING clause carries no
     * bypass. Dropping the scope alone therefore returns nothing on production,
     * so the read adopts the owning tenant for its duration. With no owning
     * agency there is no pipeline to read, and the plain read fails closed.
     *
     * @return array<string,mixed>
     */
    private function pipelineSections(Student $student): array
    {
        $read = function () use ($student): array {
            $applications = $this->rows(
                'pipeline_applications',
                Application::withoutGlobalScope(BelongsToAgencyScope::class)
                    ->where('student_id', $student->id)->orderBy('id'),
                fn (Application $a) => [
                    'id' => $a->id,
                    'agency_id' => $a->agency_id,
                    'program_id' => $a->program_id,
                    'institution_id' => $a->institution_id,
                    'intake_month' => $a->intake_month,
                    'intake_year' => $a->intake_year,
                    'status' => $a->status?->value,
                    'ack_no' => $a->ack_no,
                    'submitted_at' => $this->iso($a->submitted_at),
                    'deadline_at' => $this->iso($a->deadline_at),
                    'deferred_to_intake' => $a->deferred_to_intake,
                    'created_at' => $this->iso($a->created_at),
                ],
            );

            $applicationIds = array_column($applications, 'id');

            return [
                'pipeline_applications' => $applications,
                'pipeline_status_events' => $applicationIds === [] ? [] : $this->rows(
                    'pipeline_status_events',
                    ApplicationStatusEvent::withoutGlobalScope(BelongsToAgencyScope::class)
                        ->whereIn('application_id', $applicationIds)->orderBy('id'),
                    fn (ApplicationStatusEvent $e) => [
                        'id' => $e->id,
                        'application_id' => $e->application_id,
                        'from_status' => $e->from_status,
                        'to_status' => $e->to_status,
                        'occurred_at' => $this->iso($e->occurred_at),
                        'actor_type' => $e->actor_type?->value,
                        'note' => $e->note,
                    ],
                ),
            ];
        };

        return $student->agency_id === null
            ? $read()
            : TenantScope::runAs($student->agency_id, $read);
    }

    /** @return array<string,mixed>|null */
    private function agencySummary(?int $agencyId): ?array
    {
        if ($agencyId === null) {
            return null;
        }

        $agency = PartnerAgency::query()->select(['id', 'legal_name', 'country'])->find($agencyId);

        return $agency ? ['id' => $agency->id, 'legal_name' => $agency->legal_name, 'country' => $agency->country] : null;
    }

    // ----------------------------------------------------------------- shared

    /**
     * Read one section into an array.
     *
     * cursor() streams a row at a time and the cap bounds the result, so no
     * section can pull the whole table — or the whole process — over. One extra
     * row is asked for purely to detect that the cap bit, and it is drained
     * rather than broken out of: an abandoned cursor upsets unbuffered drivers.
     *
     * @param  callable(mixed):array<string,mixed>  $map
     * @return list<array<string,mixed>>
     */
    private function rows(string $section, Builder|Relation $query, callable $map, int $cap = self::SECTION_CAP): array
    {
        $out = [];

        foreach ($query->limit($cap + 1)->cursor() as $row) {
            if (count($out) >= $cap) {
                $this->truncated[$section] = true;

                continue;
            }
            $out[] = $map($row);
        }

        return $out;
    }

    /** Uncast date columns come back as strings; cast ones as dates. Normalise both. */
    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format(\DATE_ATOM) : (string) $value;
    }

    /** @return array<string,mixed> */
    private function meta(DataSubjectRequest $request, User $actor, string $subjectType, int $subjectId): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $actor->email,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'request_id' => $request->id,
            'note' => 'This file is the complete record VFI holds about this person as at the time above. '
                .'Each key below is one part of that record; an empty section means we hold nothing of that kind.',
            'limits' => [
                'rows_per_section' => self::SECTION_CAP,
                'ordering' => 'Log and disclosure sections are newest first.',
                'truncated_sections' => array_keys($this->truncated),
            ],
            'withheld' => [
                'Document contents. Files are described by name, type, size and checksum only — the bytes themselves are not copied into this bundle.',
                'Internal storage keys, which are pointers into VFI private storage and say nothing about the person.',
                'Sign-in credentials: the password hash, the remember-me token and the two-factor secret are never exported.',
                'The IP address and device recorded against reads of this record, which belong to whoever made the read.',
            ],
        ];
    }

    /**
     * Row counts per section. The audit trail records the SHAPE of an export, so
     * that it is evidence the export happened and not a second copy of the data.
     *
     * @param  array<string,mixed>  $sections
     * @return array<string,int>
     */
    private function sectionCounts(array $sections): array
    {
        $counts = [];

        foreach ($sections as $key => $value) {
            if ($key === 'student_record' && is_array($value)) {
                foreach ($this->sectionCounts($value) as $nested => $count) {
                    $counts['student_record.'.$nested] = $count;
                }

                continue;
            }

            $counts[$key] = match (true) {
                $value === null => 0,
                is_array($value) => array_is_list($value) ? count($value) : 1,
                default => 1,
            };
        }

        return $counts;
    }

    /** @param  array<string,int>  $counts */
    private function outcomeNote(array $counts): string
    {
        $note = 'Export written to the private disk: '.count($counts).' sections, '.array_sum($counts).' rows.';

        if ($this->truncated !== []) {
            $note .= ' Capped at '.self::SECTION_CAP.' rows in: '.implode(', ', array_keys($this->truncated)).'.';
        }

        return $note;
    }

    /**
     * Staff and auditors read the outcome, so it must never carry the subject's
     * data. Our own guard messages are written to be safe to repeat; a message
     * from anywhere else is not, so only its class is recorded and the detail
     * stays in the application log.
     */
    private function failureNote(Throwable $e): string
    {
        return $e instanceof RuntimeException
            ? 'Export failed: '.mb_substr($e->getMessage(), 0, 300)
            : 'Export failed with an unexpected '.class_basename($e).'. See the application log for details.';
    }
}
