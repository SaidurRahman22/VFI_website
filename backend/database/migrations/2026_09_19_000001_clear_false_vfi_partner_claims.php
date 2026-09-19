<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stop claiming a partnership with 391 universities that VFI has no relationship
 * with.
 *
 * Every institution in the catalogue carried `vfi_represented = true`, because
 * all three ingest sources set it that way - SeedSource, DaadSource and
 * CollegeScorecardSource each hard-coded it rather than leaving the column's
 * `false` default alone. The catalogue was populated to give the site something
 * to show; none of it came from an agreement.
 *
 * That is not a cosmetic flag. js/universities.js:93 renders a **"VFI partner"**
 * badge from it on the public directory, and SearchIndexer adds a `vfi` search
 * token so the partner console can filter on it. So the public site has been
 * telling visitors that four hundred universities are partners, and the console
 * has been letting staff filter a list of them. The client confirmed on
 * 2026-09-19 that there are no partners at all yet.
 *
 * This clears the claim in both places. It does NOT drop the column or the admin
 * toggle: the moment a real agreement exists, someone ticks "VFI partner" on
 * that university in /manage and the badge is true.
 *
 * The flags rewrite is here rather than left to `programs:reindex` because the
 * deploy runs migrations and does not run that command - so leaving it would
 * mean the badge disappeared while the search still filtered on a stale token.
 * `flags` is stored space-padded (' a b c '), which is what makes ' vfi ' a
 * clean, whole-token replacement rather than a substring match.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('institutions')
            ->where('vfi_represented', true)
            ->update(['vfi_represented' => false]);

        if (DB::getSchemaBuilder()->hasTable('program_search')) {
            DB::table('program_search')
                ->where('flags', 'like', '% vfi %')
                ->update(['flags' => DB::raw("replace(flags, ' vfi ', ' ')")]);
        }
    }

    /**
     * Deliberately irreversible.
     *
     * Putting the flag back would mean re-asserting a partnership that does not
     * exist, and there is no record of which rows were true "legitimately"
     * because none of them were. If a genuine partner needs marking, that is a
     * tick in the admin panel, not a rollback.
     */
    public function down(): void
    {
        // no-op
    }
};
