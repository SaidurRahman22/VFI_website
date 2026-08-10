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
 * Phase 2C/2E — the public content read path (docs §3). Serves the full content
 * object in the exact shape js/store.js expects, two ways:
 *   GET /api/content/bundle       → JSON (for tooling / fetch)
 *   GET /api/content/bootstrap.js → `window.VFI_BOOTSTRAP = {…};` as a classic
 *        script, so it runs synchronously BEFORE store.js — the sync accessors
 *        keep working and the pages stay static/CDN-cacheable.
 *
 * Honours: "empty means fall through" ([] / {}, never null/defaults) and
 * "order is the array" (position, new-to-front). URL fields are scheme-allow-listed
 * (http/https/mailto/relative) so a javascript:/data: URL can never reach an href.
 */
class ContentBundleController extends Controller
{
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

    public function bundle(Request $request): Response
    {
        [$json, $etag] = $this->buildJson();

        if ($this->notModified($request, $etag)) {
            return response('', 304)->withHeaders($this->cacheHeaders($etag));
        }

        return response($json, 200)->withHeaders(array_merge(
            ['Content-Type' => 'application/json'],
            $this->cacheHeaders($etag),
        ));
    }

    public function bootstrap(Request $request): Response
    {
        [$json, $etag] = $this->buildJson();

        if ($this->notModified($request, $etag)) {
            return response('', 304)->withHeaders($this->cacheHeaders($etag));
        }

        // Classic-script payload: sets the global, then store.js reads it.
        return response('window.VFI_BOOTSTRAP = '.$json.';', 200)->withHeaders(array_merge(
            ['Content-Type' => 'application/javascript; charset=utf-8'],
            $this->cacheHeaders($etag),
        ));
    }

    /** Build the content object + a stable ETag. */
    private function buildJson(): array
    {
        $bundle = [];

        foreach (self::COLLECTIONS as $key => $model) {
            $bundle[$key] = $model::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn ($row) => $row->toBundle())->all();
        }

        // URL-scheme allow-list on the link-bearing collection fields (security).
        foreach ($bundle['ppQuicklinks'] as &$q) {
            $q['url'] = $this->safeUrl($q['url'] ?? null);
        }
        unset($q);
        foreach ($bundle['ppDocs'] as &$d) {
            $d['url'] = $this->safeUrl($d['url'] ?? null);
        }
        unset($d);

        $stored = SiteContent::query()->whereIn('key', self::SINGLETONS)->pluck('value', 'key');
        foreach (self::SINGLETONS as $key) {
            $bundle[$key] = (object) ($stored[$key] ?? []);
        }

        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$json, '"'.md5($json).'"'];
    }

    /**
     * Allow only http/https/mailto and relative URLs; reject javascript:/data:/etc.
     * Returns '' for a rejected/empty value (store.js/render.js then skip it).
     */
    private function safeUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        // relative (path, anchor, query) — no scheme, no protocol-relative
        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url) && ! str_starts_with($url, '//')) {
            return $url;
        }
        if (preg_match('#^(https?|mailto):#i', $url)) {
            return $url;
        }

        return '';   // javascript:, data:, vbscript:, protocol-relative → dropped
    }

    private function notModified(Request $request, string $etag): bool
    {
        return trim((string) $request->header('If-None-Match'), '"') === trim($etag, '"');
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
