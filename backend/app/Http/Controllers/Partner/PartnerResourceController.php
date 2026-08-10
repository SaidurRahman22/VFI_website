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
            'data' => $rows->map(fn (PpDoc $d) => [
                'id' => $d->id, 'title' => $d->title, 'country' => $d->country,
                'category' => $d->category, 'date' => $d->date, 'size' => $d->size,
                'url' => UrlGuard::safe($d->url),
            ]),
            // facet lists for the filter panels (from the full catalogue, not the filtered set)
            'countries' => PpDoc::query()->whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
            'categories' => PpDoc::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ])->header('Cache-Control', 'no-store');
    }
}
