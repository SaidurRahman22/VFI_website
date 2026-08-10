<?php

namespace App\Http\Controllers;

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
use App\Models\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase 2C — the public content read path (docs §3). Returns the full content
 * object in the exact shape js/store.js expects for window.VFI_BOOTSTRAP, so the
 * synchronous accessors keep working unchanged.
 *
 * Two load-bearing rules are honoured:
 *  - "empty means fall through": empty collections serialize as [] and empty
 *    override singletons as {} — never null/defaults; the page keeps its own HTML.
 *  - "order is the array": collections come back ordered by `position` (new-to-front).
 *
 * GET-only, ETag-cacheable, no Set-Cookie (safe behind a CDN).
 */
class ContentBundleController extends Controller
{
    /** Map of frontend collection key => model class (order preserved). */
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

    /** Object-shaped singletons/maps (must serialize as {} when empty). */
    private const SINGLETONS = [
        'settings', 'countries', 'regions', 'servicesPage',
        'partnerPage', 'partnerPortal', 'media', 'pages',
    ];

    public function bundle(Request $request): Response
    {
        $bundle = [];

        foreach (self::COLLECTIONS as $key => $model) {
            // ordered by position; [] when empty (faithful fall-through)
            $bundle[$key] = $model::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn ($row) => $row->toBundle())->all();
        }

        $stored = SiteContent::query()->whereIn('key', self::SINGLETONS)->pluck('value', 'key');
        foreach (self::SINGLETONS as $key) {
            // (object) guarantees {} for an empty/absent singleton, object when populated
            $bundle[$key] = (object) ($stored[$key] ?? []);
        }

        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $etag = '"'.md5($json).'"';

        // conditional GET
        if (trim((string) $request->header('If-None-Match'), '"') === trim($etag, '"')) {
            return response('', 304)->withHeaders($this->cacheHeaders($etag));
        }

        return response($json, 200)->withHeaders(array_merge(
            ['Content-Type' => 'application/json'],
            $this->cacheHeaders($etag),
        ));
    }

    private function cacheHeaders(string $etag): array
    {
        return [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Origin',
        ];
    }
}
