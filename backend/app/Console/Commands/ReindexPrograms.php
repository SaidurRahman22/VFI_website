<?php

namespace App\Console\Commands;

use App\Services\SearchIndexer;
use Illuminate\Console\Command;

/**
 * Phase 8 — rebuild the flat `program_search` index without re-running a feed.
 * The scheduled feed runs use `--no-index` (they may each import only a slice),
 * then this runs once afterwards so the index is rebuilt a single time.
 */
class ReindexPrograms extends Command
{
    protected $signature = 'programs:reindex';

    protected $description = 'Rebuild the program search index from the catalogue tables';

    public function handle(SearchIndexer $indexer): int
    {
        $rows = $indexer->rebuild();
        $this->info("Search index rebuilt: {$rows} rows.");

        return self::SUCCESS;
    }
}
