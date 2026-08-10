<?php

namespace App\Services;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 3G — backup export + guarded restore (docs §7.2–7.3). Export shape is
 * compatible with the frontend VFI.exportAll JSON. Restore is the highest
 * blast-radius operation: owner-only, schema-validated, size-capped, and it
 * ALWAYS writes a pre-restore snapshot first so a bad restore is recoverable.
 */
class BackupService
{
    private const COLLECTIONS = [
        'events' => Event::class, 'blogs' => Blog::class, 'news' => NewsItem::class,
        'photos' => Photo::class, 'ppManagers' => PpManager::class, 'ppUpdates' => PpUpdate::class,
        'ppQuicklinks' => PpQuicklink::class, 'ppDocs' => PpDoc::class,
        'ppEmails' => PpEmail::class, 'ppNotifs' => PpNotif::class,
    ];

    private const SINGLETONS = [
        'settings', 'countries', 'regions', 'servicesPage',
        'partnerPage', 'partnerPortal', 'media', 'pages',
    ];

    /** Full content export in the VFI.exportAll shape. */
    public function export(): array
    {
        $content = [];
        foreach (self::COLLECTIONS as $key => $model) {
            $content[$key] = $model::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn ($r) => $r->toBundle())->all();
        }
        foreach (self::SINGLETONS as $key) {
            $content[$key] = (object) (SiteContent::value($key, []) ?? []);
        }

        return [
            'version' => 1,
            'exportedAt' => now()->toIso8601String(),
            'content' => $content,
            'images' => (object) [],   // managed images are files on disk (path ids), not inlined
        ];
    }

    /** Write the current state to a timestamped snapshot; return its path. */
    public function snapshot(string $reason): string
    {
        $path = 'backups/'.now()->format('Ymd-His').'-'.Str::random(6).'.json';
        Storage::disk('local')->put($path, json_encode(array_merge($this->export(), ['reason' => $reason])));

        return $path;
    }

    /** Validate a payload's shape (throws on anything malformed). */
    public function validate(array $payload): array
    {
        $content = $payload['content'] ?? $payload;
        if (! is_array($content)) {
            throw new \InvalidArgumentException('Backup has no content object.');
        }
        foreach (self::COLLECTIONS as $key => $_) {
            if (array_key_exists($key, $content) && ! is_array($content[$key])) {
                throw new \InvalidArgumentException("Collection '{$key}' must be a list.");
            }
        }
        // must contain at least one recognised key — reject random JSON
        $known = array_merge(array_keys(self::COLLECTIONS), self::SINGLETONS);
        if (empty(array_intersect(array_keys($content), $known))) {
            throw new \InvalidArgumentException('Backup does not look like VFI content.');
        }

        return $content;
    }

    /**
     * Replace all content from a validated payload. Caller MUST have snapshotted
     * first. Wrapped in a transaction; per-row audit muted (one 'restore' row).
     */
    public function restore(array $content): void
    {
        ContentAuditLog::$muted = true;
        try {
            DB::transaction(function () use ($content) {
                foreach (self::COLLECTIONS as $key => $model) {
                    DB::table((new $model)->getTable())->delete();   // wipe (full replace)
                    foreach (array_values($content[$key] ?? []) as $i => $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $proto = new $model;
                        $attrs = $proto->mapFromBundle($item);
                        if (empty($attrs['legacy_id'])) {
                            $attrs['legacy_id'] = substr(class_basename($model), 0, 1).'_'.Str::random(10);
                        }
                        $attrs['position'] = $i;
                        $model::query()->create($attrs);
                    }
                }
                foreach (self::SINGLETONS as $key) {
                    if (array_key_exists($key, $content)) {
                        $row = SiteContent::query()->where('key', $key)->first();
                        SiteContent::query()->updateOrCreate(
                            ['key' => $key],
                            ['value' => $content[$key], 'version' => ($row->version ?? 0) + 1],
                        );
                    }
                }
            });
        } finally {
            ContentAuditLog::$muted = false;
        }
    }
}
