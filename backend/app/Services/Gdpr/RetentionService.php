<?php

namespace App\Services\Gdpr;

use App\Models\ContentAuditLog;
use App\Models\Student\DocumentAccessLog;
use App\Models\Student\DocumentFile;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9B — the retention clock on stored documents.
 *
 * GDPR storage limitation says personal data may not be kept "longer than
 * necessary". Nothing in Phase 5 ever removed a blob, so a passport scan
 * uploaded in 2026 would still be on disk in 2046. This puts a per-file expiry
 * date on every blob and destroys the bytes once it passes.
 *
 * What is destroyed and what is KEPT is the whole point:
 *   - destroyed: the blob on the private disk;
 *   - kept: the document_files row, its checklist row, and every audit and
 *     access-log line about it.
 * Proving to a regulator (or to the data subject) that a file was deleted
 * requires evidence that outlives the data — a row with bytes_deleted_at set is
 * that evidence. Deleting the row too would leave nothing to point at.
 */
class RetentionService
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Set (or move) one file's expiry date by hand — a legal hold that pushes it
     * out, or an erasure request that pulls it in.
     */
    public function setClock(DocumentFile $file, \DateTimeInterface $until, User $actor): DocumentFile
    {
        if ($file->bytes_deleted_at !== null) {
            throw new RuntimeException('These bytes were already destroyed — the retention clock can no longer be changed.');
        }

        // Stored as a bare Y-m-d. The column is a DATE; keeping a time component
        // out of it means "on or before today" is a plain, index-friendly string
        // comparison on every driver, with no off-by-one at the boundary.
        $date = Carbon::instance($until)->toDateString();
        $before = ['retention_until' => $file->retention_until];

        return DB::transaction(function () use ($file, $date, $before, $actor) {
            $file->forceFill(['retention_until' => $date])->save();

            ContentAuditLog::record(
                'retention_clock',
                'document_file',
                (string) $file->id,
                $before,
                ['retention_until' => $date, 'actor_user_id' => $actor->id],
            );

            return $file;
        });
    }

    /**
     * Stamp the default clock on files that have none, oldest first. Returns how
     * many were stamped. Bounded on purpose: run it repeatedly (it is idempotent)
     * rather than sweeping a table of hundreds of thousands of rows in one go.
     */
    public function applyDefaultClocks(int $limit = 1000): int
    {
        $limit = max(1, $limit);
        $years = max(1, (int) config('documents.retention_years', 7));

        // Two columns for at most $limit rows — never the whole table, never the
        // hydrated models (nothing here needs them).
        $rows = DB::table('document_files')
            ->whereNull('retention_until')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'created_at']);

        if ($rows->isEmpty()) {
            return 0;
        }

        // Group by the computed date: a batch costs a handful of UPDATEs instead
        // of one round-trip per file.
        $byDate = [];
        foreach ($rows as $row) {
            $from = $row->created_at ? Carbon::parse($row->created_at) : Carbon::now();
            $byDate[$from->copy()->addYears($years)->toDateString()][] = (int) $row->id;
        }

        $stamped = 0;
        foreach ($byDate as $date => $ids) {
            // whereNull again: another worker may have stamped these since the
            // select, and an existing clock (a legal hold) must never be moved.
            // No updated_at touch — this is a system stamp, not a content edit.
            $stamped += DB::table('document_files')
                ->whereIn('id', $ids)
                ->whereNull('retention_until')
                ->update(['retention_until' => $date]);
        }

        if ($stamped > 0) {
            ContentAuditLog::record('retention_clock_default', 'document_files', null, null, [
                'stamped' => $stamped,
                'years' => $years,
                'limit' => $limit,
            ]);
        }

        return $stamped;
    }

    /**
     * Files whose clock has passed and whose bytes are still on disk. Shared by
     * purgeExpired() and the command's --dry-run so both report the same set.
     *
     * @return Collection<int, DocumentFile>
     */
    public function dueForPurge(int $limit = 500): Collection
    {
        return DocumentFile::query()
            ->whereNotNull('retention_until')
            // Plain <= keeps the retention_until index usable; whereDate() would
            // wrap the column in a function and force a full scan.
            ->where('retention_until', '<=', Carbon::now()->toDateString())
            ->whereNull('bytes_deleted_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['id', 'student_id', 'storage_key', 'retention_until']);
    }

    /**
     * Destroy the bytes of every expired file in one bounded batch and stamp the
     * moment it happened. Returns how many blobs were destroyed.
     */
    public function purgeExpired(int $limit = 500): int
    {
        $due = $this->dueForPurge($limit);
        if ($due->isEmpty()) {
            return 0;
        }

        $purged = 0;
        $failed = 0;

        foreach ($due as $file) {
            try {
                $this->storage->delete((string) $file->storage_key);

                // Row KEPT, only stamped. whereNull stops a concurrent run from
                // rewriting a deletion time that is already on the record.
                $stamped = DB::table('document_files')
                    ->where('id', $file->id)
                    ->whereNull('bytes_deleted_at')
                    ->update(['bytes_deleted_at' => Carbon::now()]);

                if ($stamped === 0) {
                    continue;   // another worker got there first
                }

                // Per-file evidence belongs in the append-only access log, which
                // outlives the blob. The content audit gets ONE row per batch
                // below — a row per file would flood it at scale.
                DocumentAccessLog::record([
                    'document_file_id' => $file->id,
                    'student_id' => $file->student_id,
                    'actor_user_id' => null,   // the scheduler, not a person
                    'action' => 'purge',
                ]);

                $purged++;
            } catch (\Throwable $e) {
                // One unreadable blob must not abandon the rest of the batch.
                $failed++;
                report($e);
            }
        }

        if ($purged > 0 || $failed > 0) {
            ContentAuditLog::record('retention_purge', 'document_files', null, null, [
                'purged' => $purged,
                'failed' => $failed,
                'due' => $due->count(),
                'cutoff' => Carbon::now()->toDateString(),
            ]);
        }

        return $purged;
    }
}
