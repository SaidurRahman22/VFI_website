<?php

namespace Tests\Feature;

use App\Models\Content\Photo;
use App\Models\Content\PpManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The seeding migration skips the test database on purpose - RefreshDatabase
 * would otherwise put its rows into every test, and four tests broke the moment
 * it did, one of them the bundle test that exists to check the EMPTY case.
 *
 * That skip would leave the migration completely unexercised, so this runs it
 * directly. What matters is not that it inserts - it is that it REFUSES to,
 * which is the whole safety argument for running it against a live site.
 */
class SeedEmptyCollectionsTest extends TestCase
{
    use RefreshDatabase;

    /** Run the migration's up() with the testing guard stepped around. */
    private function runSeed(): void
    {
        $migration = require database_path('migrations/2026_09_19_000002_seed_empty_content_collections.php');

        // The guard reads app()->environment(), so lie about it for this call
        // only - the alternative is a second copy of the row data in the test,
        // which would pass while the real migration rotted.
        $previous = app()->environment();
        app()['env'] = 'production';

        try {
            $migration->up();
        } finally {
            app()['env'] = $previous;
        }
    }

    public function test_it_fills_a_collection_that_is_completely_empty(): void
    {
        $this->assertSame(0, Photo::count());

        $this->runSeed();

        $this->assertGreaterThan(0, Photo::count());
        $this->assertGreaterThan(0, PpManager::count());

        // Every photo points at artwork bundled in the repo, never an upload:
        // ImageService::isManaged() is false for these, so the reference-counted
        // delete can never remove a file the site ships with.
        foreach (Photo::all() as $photo) {
            $this->assertStringStartsWith('assets/img/', (string) $photo->img_id);
        }
    }

    public function test_it_leaves_a_collection_that_already_has_a_row_alone(): void
    {
        Photo::create(['caption' => 'A real photo somebody added', 'img_id' => 'assets/img/campus.jpg']);

        $this->runSeed();

        $this->assertSame(1, Photo::count(), 'an existing row must not be joined by eight seeded ones');
        $this->assertSame('A real photo somebody added', Photo::first()->caption);

        // a different, still-empty collection is unaffected by that
        $this->assertGreaterThan(0, PpManager::count());
    }

    /**
     * The case that matters most on a live site: somebody added photos and then
     * deleted them. That is a decision, and re-filling the table would silently
     * undo it.
     */
    public function test_it_leaves_a_collection_whose_rows_were_removed_alone(): void
    {
        $photo = Photo::create(['caption' => 'Removed on purpose', 'img_id' => 'assets/img/campus.jpg']);
        $photo->delete();

        $this->assertSame(0, Photo::count());
        $this->assertSame(1, Photo::withTrashed()->count());

        $this->runSeed();

        $this->assertSame(0, Photo::count(), 'a cleared-out collection must stay cleared out');
        $this->assertSame(1, Photo::withTrashed()->count());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->runSeed();
        $after = Photo::count();

        $this->runSeed();

        $this->assertSame($after, Photo::count());
        $this->assertSame(
            $after,
            DB::table('photos')->distinct()->count('legacy_id'),
            'legacy_id is what the console and the audit log key on, so duplicates would be worse than extra rows'
        );
    }
}
