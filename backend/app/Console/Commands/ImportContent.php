<?php

namespace App\Console\Commands;

use App\Models\Content\Blog;
use App\Models\Content\Event;
use App\Models\Content\NewsItem;
use App\Models\Content\Photo;
use App\Models\Content\PpDoc;
use App\Models\Content\PpEmail;
use App\Models\Content\PpManager;
use App\Models\Content\PpNotif;
use App\Models\Content\PpQuicklink;
use App\Models\Content\PpUpdate;
use App\Models\ContentAuditLog;
use App\Models\SiteContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2D — idempotent content importer (docs §5). Reads a VFI.exportAll JSON
 * (or a bare content object) and upserts on `legacy_id`; the array index becomes
 * `position` (index 0 = front). Re-running produces no duplicates and no id churn.
 *
 *   php artisan content:import database/content/demo.json
 */
class ImportContent extends Command
{
    protected $signature = 'content:import {file : path to an exportAll (or content) JSON}';

    protected $description = 'Import/refresh site content from a VFI export JSON (idempotent, upsert on legacy_id).';

    private const COLLECTIONS = [
        'events' => Event::class,
        'blogs' => Blog::class,
        'news' => NewsItem::class,
        'photos' => Photo::class,
        'ppManagers' => PpManager::class,
        'ppUpdates' => PpUpdate::class,
        'ppQuicklinks' => PpQuicklink::class,
        'ppDocs' => PpDoc::class,
        'ppEmails' => PpEmail::class,
        'ppNotifs' => PpNotif::class,
    ];

    private const SINGLETONS = [
        'settings', 'countries', 'regions', 'servicesPage',
        'partnerPage', 'partnerPortal', 'media', 'pages',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            $this->error('Invalid JSON.');

            return self::FAILURE;
        }
        // accept either {content:{…}} (exportAll) or a bare content object
        $content = $raw['content'] ?? $raw;

        // Mute per-row audit; the import writes ONE summary audit row instead.
        ContentAuditLog::$muted = true;
        $summary = [];

        DB::transaction(function () use ($content, &$summary) {
            foreach (self::COLLECTIONS as $key => $modelClass) {
                $rows = $content[$key] ?? [];
                if (! is_array($rows)) {
                    continue;
                }
                $count = 0;
                foreach (array_values($rows) as $i => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    /** @var \App\Models\Content\ContentItem $proto */
                    $proto = new $modelClass;
                    $attrs = $proto->mapFromBundle($item);
                    if (empty($attrs['legacy_id'])) {
                        $attrs['legacy_id'] = Str::slug($key).'_'.Str::random(8);
                    }
                    $attrs['position'] = $i;   // array index = position (0 = front)
                    $modelClass::query()->updateOrCreate(
                        ['legacy_id' => $attrs['legacy_id']],
                        $attrs,
                    );
                    $count++;
                }
                $summary[$key] = $count;
                $this->line(sprintf('  %-14s %d', $key, $count));
            }

            foreach (self::SINGLETONS as $key) {
                if (! array_key_exists($key, $content)) {
                    continue;
                }
                $existing = SiteContent::query()->where('key', $key)->first();
                SiteContent::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $content[$key], 'version' => ($existing->version ?? 0) + 1],
                );
                $this->line("  singleton: {$key}");
            }
        });

        ContentAuditLog::$muted = false;
        ContentAuditLog::record('import', 'content', null, null, $summary);

        $this->info('Content imported.');

        return self::SUCCESS;
    }
}
