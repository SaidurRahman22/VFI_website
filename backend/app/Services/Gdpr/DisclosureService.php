<?php

namespace App\Services\Gdpr;

use App\Models\ContentAuditLog;
use App\Models\DocumentDisclosure;
use App\Models\Student\DocumentFile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9B — the register of documents that left VFI.
 *
 * Article 15 gives a data subject the right to be told the recipients their
 * data was disclosed to. document_access_log cannot answer that: it records who
 * VIEWED a file inside the system, and an internal view is not a disclosure.
 * Sending a transcript to a university is, and it is a human action nothing can
 * infer — so it is written here, deliberately, at the moment it happens.
 *
 * Append-only by design: there is no update path. A disclosure recorded in
 * error is answered with a correcting note, not by rewriting history.
 */
class DisclosureService
{
    /**
     * @param  array{document_file_id?:int|null, student_id?:int|null, recipient_name:string,
     *               recipient_type:string, lawful_basis:string, note?:string|null,
     *               disclosed_at?:\DateTimeInterface|string|null}  $attrs
     */
    public function record(array $attrs, User $actor): DocumentDisclosure
    {
        $name = trim((string) ($attrs['recipient_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('A recipient name is required — a disclosure with no recipient records nothing.');
        }

        // Allow-lists, not free text: these two fields are what a regulator reads
        // first, and a typo'd basis is worse than no record at all.
        $recipientType = (string) ($attrs['recipient_type'] ?? '');
        if (! in_array($recipientType, DocumentDisclosure::RECIPIENT_TYPES, true)) {
            throw new RuntimeException('Unknown recipient type — use one of: '.implode(', ', DocumentDisclosure::RECIPIENT_TYPES).'.');
        }

        $lawfulBasis = (string) ($attrs['lawful_basis'] ?? '');
        if (! in_array($lawfulBasis, DocumentDisclosure::LAWFUL_BASES, true)) {
            throw new RuntimeException('Unknown lawful basis — use one of: '.implode(', ', DocumentDisclosure::LAWFUL_BASES).'.');
        }

        $fileId = isset($attrs['document_file_id']) ? (int) $attrs['document_file_id'] : null;
        $studentId = isset($attrs['student_id']) ? (int) $attrs['student_id'] : null;

        if ($fileId !== null) {
            $file = DocumentFile::query()->find($fileId, ['id', 'student_id']);
            if ($file === null) {
                throw new RuntimeException('That document file does not exist.');
            }
            // The file owns the answer to "whose data was this?" — trust it over
            // a student_id the caller passed alongside.
            $studentId = (int) $file->student_id;
        }

        if ($fileId === null && $studentId === null) {
            throw new RuntimeException('A disclosure must name the file or the student it concerns.');
        }

        $disclosedAt = $this->when($attrs['disclosed_at'] ?? null);

        return DB::transaction(function () use ($name, $recipientType, $lawfulBasis, $fileId, $studentId, $disclosedAt, $attrs, $actor) {
            $disclosure = DocumentDisclosure::create([
                'document_file_id' => $fileId,
                'student_id' => $studentId,
                'recipient_name' => mb_substr($name, 0, 190),
                'recipient_type' => $recipientType,
                'lawful_basis' => $lawfulBasis,
                'note' => filled($attrs['note'] ?? null) ? mb_substr(trim((string) $attrs['note']), 0, 2000) : null,
                'disclosed_by_user_id' => $actor->id,
                'disclosed_at' => $disclosedAt,
                'created_at' => Carbon::now(),
            ]);

            // The audit points AT the disclosure rather than copying it: the
            // recipient's name lives in one table, not two.
            ContentAuditLog::record(
                'document_disclosure',
                'document_file',
                $fileId !== null ? (string) $fileId : null,
                null,
                [
                    'disclosure_id' => $disclosure->id,
                    'student_id' => $studentId,
                    'recipient_type' => $recipientType,
                    'lawful_basis' => $lawfulBasis,
                    'disclosed_at' => $disclosedAt->toIso8601String(),
                    'actor_user_id' => $actor->id,
                ],
            );

            return $disclosure;
        });
    }

    /** A disclosure is often logged after the fact, so it may be back-dated; unset means now. */
    private function when(mixed $value): Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                throw new RuntimeException('The disclosure date could not be read.');
            }
        }

        return Carbon::now();
    }
}
