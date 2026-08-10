<?php

namespace App\Http\Controllers;

use App\Models\TaxonomyTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 — the ONE served vocabulary source (docs §1). Public, cacheable
 * reference data (no PII); the front-end reads it instead of its five divergent
 * hardcoded option lists. `?kinds=country,level` narrows the response.
 */
class TaxonomyController extends Controller
{
    /** GET /api/taxonomy[?kinds=country,level,...] */
    public function index(Request $request): JsonResponse
    {
        $q = TaxonomyTerm::query()->where('active', true);

        if ($kinds = $request->query('kinds')) {
            $q->whereIn('kind', array_filter(array_map('trim', explode(',', (string) $kinds))));
        }

        $grouped = $q->orderBy('kind')->orderBy('position')->orderBy('id')->get()
            ->groupBy('kind')
            ->map(fn ($terms) => $terms->map(fn (TaxonomyTerm $t) => ['value' => $t->value, 'label' => $t->label])->values());

        return response()->json(['vocabularies' => $grouped])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
