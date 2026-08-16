<?php

namespace App\Console\Commands;

use App\Services\Gdpr\RetentionService;
use Illuminate\Console\Command;

/**
 * Phase 9B — the scheduled arm of the retention clock (see RetentionService).
 *
 * Runs in bounded batches so a backlog is worked off over several runs instead
 * of one run holding the disk and the DB for an hour. --dry-run answers "what
 * would tonight delete?" without touching anything, which is the only safe way
 * to review a destructive job before it runs unattended.
 */
class PurgeExpiredDocumentBytes extends Command
{
    protected $signature = 'documents:purge-expired {--limit=500} {--dry-run}';

    protected $description = 'Destroy the bytes of document files whose retention clock has passed (rows and audit trail are kept)';

    public function handle(RetentionService $retention): int
    {
        // Clamp: an operator typo must not turn one run into a table sweep.
        $limit = max(1, min(5000, (int) $this->option('limit')));

        if ($this->option('dry-run')) {
            $due = $retention->dueForPurge($limit);
            $this->line("Dry run: {$due->count()} file(s) would have their bytes destroyed (limit {$limit}). Nothing was changed.");

            return self::SUCCESS;
        }

        $purged = $retention->purgeExpired($limit);
        $this->info("Retention purge: bytes destroyed for {$purged} file(s) (limit {$limit}); rows, checklists and audit trail kept.");

        return self::SUCCESS;
    }
}
