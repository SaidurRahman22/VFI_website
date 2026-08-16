<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled catalogue maintenance (Phase 8)
|--------------------------------------------------------------------------
| The US feed (College Scorecard) is rate-limited to ~30 requests/hour on the
| shared DEMO_KEY, so a full import spans several hours. The source records a
| resume point when it hits the quota, so running it hourly walks the catalogue
| to completion on its own and then simply re-checks for updates. With a real
| CATALOGUE_SCORECARD_KEY the quota lifts and a run finishes in one pass.
|
| Requires the system cron to invoke `php artisan schedule:run` every minute.
*/
Schedule::command('programs:ingest --source=scorecard --no-index')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

// Germany (DAAD) has no quota — a nightly refresh is enough.
Schedule::command('programs:ingest --source=daad --no-index')
    ->dailyAt('02:30')
    ->withoutOverlapping(60)
    ->runInBackground();

// Rebuild the flat search index once, after the feeds have run.
Schedule::command('programs:reindex')
    ->dailyAt('03:15')
    ->withoutOverlapping(30)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Retention (Phase 9B)
|--------------------------------------------------------------------------
| Destroy the bytes of document files whose retention clock has passed. The
| rows, the checklist and every audit/access-log entry are kept — proving a
| deletion happened requires keeping the proof of it.
|
| Batched deliberately: 500 files a night keeps the blob deletes off the hot
| path rather than attempting an unbounded purge in one go.
*/
Schedule::command('documents:purge-expired --limit=500')
    ->dailyAt('04:10')
    ->withoutOverlapping(60)
    ->runInBackground();
