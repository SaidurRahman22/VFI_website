<?php

use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 8B — the served vocabularies are reference data the search/console need,
 * so they seed via a migration (git-sync runs `migrate`, not `db:seed`).
 * Idempotent (upsert on kind+value); also populates every test DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TaxonomySeeder)->run();
    }

    public function down(): void
    {
        // Leave content-taxonomy terms (blog_category etc.) intact; only remove
        // the search vocabularies this seeder owns.
        \App\Models\TaxonomyTerm::query()->whereIn('kind', [
            'country', 'level', 'study_area', 'discipline_area', 'duration_band',
            'intake', 'study_level', 'nationality', 'test',
        ])->delete();
    }
};
