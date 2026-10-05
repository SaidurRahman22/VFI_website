<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Content\PpDoc;
use App\Support\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7 — Learning Resources as a REAL server query (docs §7). Replaces the
 * fake client filter that rendered every ppDocs row. Resources are admin-managed
 * shared content (not tenant-scoped); URLs are scheme-allow-listed on read.
 *
 * Every row currently on live is PLACEHOLDER, and the API now says so. The four
 * rows came from the 2026_09_19 seed migration, which filled seven empty console
 * panels so a new agency would not sign in to a wall of blank screens. Their
 * `url` is `partner-resources.html` — the page the partner is already looking
 * at — so each row rendered a "Download" link that reopened the same page while
 * the meta line beside it said "PDF". That is a control that cannot work
 * dressed as one that can. `placeholder` lets the page label it honestly
 * instead; see isPlaceholder() for what counts as one and why no file is
 * invented to fix it.
 *
 * The flag is derived, never stored, so it needs no migration and no editor
 * discipline: uploading the real document is the only action required, and it
 * clears the label by itself.
 */
class PartnerResourceController extends Controller
{
    /** GET /api/partner/resources?country=&category=&q= */
    public function index(Request $request): JsonResponse
    {
        $q = PpDoc::query()->orderBy('position')->orderBy('id');

        if ($country = $request->query('country')) {
            $q->where('country', $country);
        }
        if ($category = $request->query('category')) {
            $q->where('category', $category);
        }
        if ($kw = trim((string) $request->query('q'))) {
            $q->where('title', 'like', "%{$kw}%");
        }

        $rows = $q->get();

        return response()->json([
            'data' => $rows->map(function (PpDoc $d) {
                $placeholder = $this->isPlaceholder($d);

                return [
                    'id' => $d->id, 'title' => $d->title, 'country' => $d->country,
                    'category' => $d->category,
                    /*
                     * Suppressed on a placeholder, for the same reason url is.
                     * The seeded rows carry size "PDF" and date "Current", and
                     * the card prints them into its meta line — so a row the
                     * API has just declared has no file still read
                     * "All · Agreements · PDF", "Current", and then
                     * "Sample entry — no file uploaded yet". Two of those three
                     * are claims about a document that does not exist, and the
                     * card contradicted itself in the space of one line.
                     */
                    'date' => $placeholder ? null : $d->date,
                    'size' => $placeholder ? null : $d->size,
                    /*
                     * null, not the stored value. The stored value is the thing
                     * that is wrong: handing back `partner-resources.html` lets
                     * the page build a link, and a link that reopens the page
                     * you are on is worse than no link, because the partner
                     * assumes the download failed and tries again.
                     */
                    'url' => $placeholder ? null : UrlGuard::safe($d->url),
                    'placeholder' => $placeholder,
                ];
            }),
            // facet lists for the filter panels (from the full catalogue, not the filtered set)
            'countries' => PpDoc::query()->whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
            'categories' => PpDoc::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * A row that names a document nobody has uploaded yet.
     *
     * THE URL DECIDES, and nothing else. The obvious alternative was to key off
     * the seed migration's `legacy_id` = `seed_pp_docs_N` marker, and it is
     * wrong: the marker is immutable (ContentItem mints legacy_id once and
     * never again), so the moment the VFI desk edits one of those four rows to
     * point at the real PDF, a marker-based flag would go on calling a genuine
     * document a sample for ever. The url answers every case the marker
     * answers, and that one correctly, so the marker buys nothing.
     *
     * Not a document means: nothing there at all, or a SAME-SITE .html path —
     * which can only be one of this site's own pages, never a file to download.
     * Deliberately not applied to off-site links: a university's guidance page
     * at https://… IS a real resource even though it is HTML, and calling it a
     * placeholder would be the opposite lie.
     *
     * What this does NOT do is invent a file. There is no PDF behind the four
     * rows on live; the honest thing is for the console to label them and for
     * the desk to upload the real documents, at which point the label
     * disappears on its own with nothing to remember.
     */
    private function isPlaceholder(PpDoc $d): bool
    {
        $url = trim((string) UrlGuard::safe($d->url));
        if ($url === '') {
            return true;
        }

        // Scheme-bearing (http:, https:, mailto:) or protocol-relative —
        // somebody chose a destination off this site, so take them at their
        // word whatever it ends in.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url) || str_starts_with($url, '//')) {
            return false;
        }

        // Same-site path, relative or root-relative. `/docs/guide.pdf` is a
        // real file; `partner-resources.html` and `/partner-resources.html` are
        // pages. Query/fragment trimmed first so `…?x=1` is caught too.
        $path = mb_strtolower((string) preg_replace('/[?#].*$/', '', $url));

        // A bare `#` or `?x=1` has no path left at all — it reloads the current
        // page, which is the same dead "Download" by another spelling.
        return $path === '' || str_ends_with($path, '.html') || str_ends_with($path, '.htm');
    }
}
