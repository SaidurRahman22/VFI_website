<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\ContentAuditLog;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\StudentDocument;
use App\Models\Student\StudentTimelineEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9A — the staff document review write path.
 *
 * Phase 5 built the upload pipeline and the `verified` / `rejected` states, but
 * nothing could ever WRITE them: a student uploaded a passport scan and it sat
 * at `uploaded` forever. This is the missing half — the only place in the app
 * that moves a document out of `uploaded`.
 *
 * Every decision here is a human asserting that a legal document is genuine, so
 * each one is: guarded (state machine), attributed (verified_by + timestamp),
 * audited twice (content audit + the document access log used for GDPR/onward
 * disclosure), and surfaced to the student on their timeline.
 */
class DocumentReviewService
{
    /** Mark a document verified. Only an uploaded, clean-scanned file qualifies. */
    public function verify(StudentDocument $doc, User $staff): StudentDocument
    {
        $this->assertReviewable($doc);

        return DB::transaction(function () use ($doc, $staff) {
            $before = $this->snapshot($doc);

            $doc->forceFill([
                'status' => DocumentStatus::Verified,
                'verified_by' => $staff->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->record($doc, $staff, 'verify', $before);
            $this->tellStudent($doc, 'ok', 'Document verified',
                $this->typeName($doc).' has been checked and verified by VFI.');

            return $doc;
        });
    }

    /**
     * Reject a document with a reason the student will read verbatim, and unlock
     * the slot so they can upload a replacement.
     */
    public function reject(StudentDocument $doc, User $staff, string $reason): StudentDocument
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required — the student is shown it verbatim.');
        }
        $this->assertReviewable($doc);

        return DB::transaction(function () use ($doc, $staff, $reason) {
            $before = $this->snapshot($doc);

            $doc->forceFill([
                'status' => DocumentStatus::Rejected,
                'verified_by' => $staff->id,      // who made the decision
                'verified_at' => now(),           // when it was decided
                'rejection_reason' => mb_substr($reason, 0, 2000),
            ])->save();

            $this->record($doc, $staff, 'reject', $before);
            $this->tellStudent($doc, 'bad', 'Document needs replacing',
                $this->typeName($doc).' could not be accepted. '.$reason);

            return $doc;
        });
    }

    /**
     * Undo a verification (staff error) — back to `uploaded` so it can be
     * reviewed again or replaced. Without this a mis-verified document is locked
     * forever: the student cannot re-upload over a verified slot.
     */
    public function reopen(StudentDocument $doc, User $staff, string $reason): StudentDocument
    {
        if ($doc->status !== DocumentStatus::Verified) {
            throw new RuntimeException('Only a verified document can be reopened.');
        }

        return DB::transaction(function () use ($doc, $staff, $reason) {
            $before = $this->snapshot($doc);

            $doc->forceFill([
                'status' => DocumentStatus::Uploaded,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ])->save();

            $this->record($doc, $staff, 'reopen', $before, ['reason' => trim($reason)]);
            $this->tellStudent($doc, 'info', 'Document re-opened for review',
                $this->typeName($doc).' is being checked again by VFI.');

            return $doc;
        });
    }

    /** A document is reviewable only when uploaded and its file passed the scan. */
    private function assertReviewable(StudentDocument $doc): void
    {
        if ($doc->status !== DocumentStatus::Uploaded) {
            throw new RuntimeException('Only an uploaded document can be reviewed (this one is '.$doc->status->value.').');
        }
        $file = $doc->file;
        if (! $file || ! $file->isReadable()) {
            throw new RuntimeException('The file is missing or has not passed the virus scan yet.');
        }
    }

    private function snapshot(StudentDocument $doc): array
    {
        return [
            'status' => $doc->status->value,
            'verified_by' => $doc->verified_by,
            'verified_at' => optional($doc->verified_at)->toIso8601String(),
            'rejection_reason' => $doc->rejection_reason,
        ];
    }

    /** Two audit trails: the content log, and the document access log (GDPR). */
    private function record(StudentDocument $doc, User $staff, string $action, array $before, array $extra = []): void
    {
        ContentAuditLog::record(
            $action,
            'student_document',
            (string) $doc->id,
            $before,
            $this->snapshot($doc->fresh()) + $extra + ['actor_user_id' => $staff->id],
        );

        if ($doc->file_id) {
            DocumentAccessLog::record([
                'document_file_id' => $doc->file_id,
                'student_id' => $doc->student_id,
                'actor_user_id' => $staff->id,
                'action' => $action,
                'ip' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 255),
            ]);
        }
    }

    /** The student sees the outcome on their tracking timeline. */
    private function tellStudent(StudentDocument $doc, string $tone, string $title, string $body): void
    {
        StudentTimelineEvent::create([
            'student_id' => $doc->student_id,
            'occurred_on' => now()->toDateString(),
            'tone' => $tone,
            'icon' => 'doc',
            'title' => $title,
            'body' => mb_substr($body, 0, 1000),
            'position' => 0,
        ]);
    }

    private function typeName(StudentDocument $doc): string
    {
        return $doc->documentType?->name ?? 'Your document';
    }
}
