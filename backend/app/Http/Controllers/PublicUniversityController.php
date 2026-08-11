<?php

namespace App\Http\Controllers;

use App\Models\Catalogue\Institution;
use App\Models\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public student-facing university directory (Phase 8, student side). Reads the
 * SAME public catalogue as the partner console but with no auth — universities
 * are public reference data (no PII). List + detail + a country facet for the
 * search dropdown. Cacheable and per-IP rate-limited; every filter is a bound
 * query (no user string reaches SQL unparameterised).
 */
class PublicUniversityController extends Controller
{
    /** GET /api/universities?country=&q=&page= — paged directory. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'string', 'max:90'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = Institution::query()->withCount('programs');

        if (! empty($data['country'])) {
            $query->where('country', $data['country']);
        }
        if (($kw = trim((string) ($data['q'] ?? ''))) !== '') {
            $needle = '%'.mb_strtolower($kw).'%';
            $query->where(function ($w) use ($needle) {
                $w->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(city) like ?', [$needle]);
            });
        }

        $page = $query->orderByDesc('programs_count')->orderBy('name')->paginate(12);

        return response()->json([
            'data' => collect($page->items())->map(fn (Institution $i) => $this->card($i)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ])->header('Cache-Control', 'public, max-age=120');
    }

    /** GET /api/universities/meta — countries with a university, for the dropdown. */
    public function meta(): JsonResponse
    {
        $countries = Institution::query()
            ->select('country', DB::raw('count(*) as n'))
            ->groupBy('country')->orderBy('country')->get()
            ->map(fn ($r) => ['country' => $r->country, 'count' => (int) $r->n])->values();

        return response()->json([
            'countries' => $countries,
            'total' => Institution::count(),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    /** GET /api/universities/{institution} — detail + aggregated programs. */
    public function show(int $institution): JsonResponse
    {
        $inst = Institution::with(['programs' => fn ($q) => $q->with('intakes', 'requirements')->orderBy('level')->orderBy('title')])
            ->find($institution);

        if (! $inst) {
            return response()->json(['message' => 'University not found.'], 404)->header('Cache-Control', 'no-store');
        }

        $programs = $inst->programs;
        $tuitions = $programs->pluck('tuition_fee_minor')->filter()->values();
        $seasons = $programs->flatMap(fn ($p) => $p->intakes->pluck('season_label'))->filter()->unique()->values();
        $tests = $programs->flatMap(fn ($p) => $p->requirements->where('is_required', true)->pluck('test'))->unique()->values();

        $related = Institution::where('country', $inst->country)
            ->where('id', '!=', $inst->id)->withCount('programs')
            ->orderByDesc('programs_count')->limit(4)->get()
            ->map(fn (Institution $i) => $this->card($i));

        return response()->json(['university' => [
            'id' => $inst->id,
            'name' => $inst->name,
            'tagline' => $inst->tagline,
            'country' => $inst->country,
            'province_state' => $inst->province_state,
            'city' => $inst->city,
            'website' => $inst->website,
            'logo' => $inst->logoUrl(),
            'hero' => $inst->heroUrl(),
            'profile' => [
                'overview' => $inst->overview,
                'stats' => array_values($inst->overview_stats_json ?? []),
                'rankings' => array_values($inst->rankings_json ?? []),
                'ranking' => array_filter([
                    'world' => $inst->ranking_world, 'national' => $inst->ranking_national, 'note' => $inst->ranking_note,
                ]),
                'intake_blocks' => collect($inst->intakes_json ?? [])->map(fn ($b) => [
                    'name' => $b['name'] ?? '',
                    'month' => $b['month'] ?? '',
                    'note' => $b['note'] ?? '',
                    'image' => ! empty($b['image']) ? $this->assetUrl($b['image']) : null,
                ])->values(),
                'cost' => array_filter([
                    'note' => $inst->cost_note, 'living' => $inst->living_cost_note, 'accommodation' => $inst->accommodation_note,
                ]),
                'cost_rows' => array_values($inst->cost_rows_json ?? []),
                'admissions' => array_values($inst->admissions_json ?? []),
                'admission' => array_filter([
                    'academic' => $inst->admission_academic, 'english' => $inst->admission_english,
                ]),
                'placement' => array_filter([
                    'note' => $inst->placement_note, 'salary' => $inst->salary_note,
                    'rate' => $inst->placement_rate, 'alumni' => $inst->alumni_note,
                    'recruiters' => array_values(array_filter(array_column($inst->recruiters_json ?? [], 'name'))),
                    'jobs' => array_values($inst->jobs_json ?? []),
                ]),
                'services' => array_values($inst->services_json ?? []),
                'scholarships' => array_values($inst->scholarships_json ?? []),
                'faqs' => array_values($inst->faqs_json ?? []),
                'gallery' => collect($inst->gallery_json ?? [])
                    ->map(fn ($p) => preg_match('#^https?://#', (string) $p) ? $p : '/storage/'.ltrim((string) $p, '/'))
                    ->values(),
            ],
            'is_major_city' => $inst->is_major_city,
            'has_own_english_test' => $inst->has_own_english_test,
            'offer_tat_band' => $inst->offer_tat_band,
            'offer_acceptance_band' => $inst->offer_acceptance_band,
            'affordability_band' => $inst->affordability_band,
            'interview_required' => $inst->interview_required,
            'vfi_represented' => $inst->vfi_represented,
            'stats' => [
                'programs' => $programs->count(),
                'levels' => $programs->pluck('level')->unique()->values(),
                'study_areas' => $programs->pluck('study_area')->filter()->unique()->values(),
                'seasons' => $seasons,
                'tests_required' => $tests,
                'scholarship_available' => (bool) $programs->firstWhere('scholarship_available', true),
                'tuition_min' => $tuitions->min(),
                'tuition_max' => $tuitions->max(),
                'tuition_currency' => optional($programs->firstWhere('tuition_currency', '!=', null))->tuition_currency,
            ],
            'courses' => $programs->take(80)->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'level' => $p->level,
                'study_area' => $p->study_area,
                'duration_band' => $p->duration_band,
                'tuition' => $p->tuition_fee_minor !== null ? ['minor' => $p->tuition_fee_minor, 'currency' => $p->tuition_currency] : null,
                'is_stem' => $p->is_stem,
                'scholarship_available' => $p->scholarship_available,
                'seasons' => $p->intakes->pluck('season_label')->filter()->unique()->values(),
            ])->values(),
            'related' => $related,
        ], 'defaults' => $this->pageDefaults()])->header('Cache-Control', 'public, max-age=120');
    }

    /** Map a stored asset path to a public URL (absolute URLs pass through). */
    private function assetUrl(string $key): string
    {
        return preg_match('#^https?://#', $key) ? $key : '/storage/'.ltrim($key, '/');
    }

    /**
     * Staff-owned fallback copy for the detail template (the `universityPage`
     * singleton, edited at /manage/university-defaults). Everything the page
     * would otherwise hardcode lives here.
     */
    private function pageDefaults(): array
    {
        $d = SiteContent::value('universityPage', []) ?: [];

        $seasons = [];
        foreach ($d['seasons'] ?? [] as $s) {
            $key = strtolower(trim((string) ($s['key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $seasons[$key] = [
                'month' => $s['month'] ?? '',
                'note' => $s['note'] ?? '',
                'image' => ! empty($s['image']) ? $this->assetUrl($s['image']) : null,
            ];
        }

        return [
            'seasons' => $seasons,
            'intake_footnote' => $d['intake_footnote'] ?? null,
            'cost_intro' => $d['cost_intro'] ?? null,
            'cost_footnote' => $d['cost_footnote'] ?? null,
            'scholarship_note' => $d['scholarship_note'] ?? null,
            'faqs' => array_values($d['faqs'] ?? []),
            'interest_options' => array_values(array_filter(array_column($d['interest_options'] ?? [], 'label'))),
        ];
    }

    private function card(Institution $i): array
    {
        $loc = array_values(array_filter([$i->city, $i->province_state, $i->country]));

        return [
            'id' => $i->id,
            'name' => $i->name,
            'tagline' => $i->tagline,
            'logo' => $i->logoUrl(),
            'country' => $i->country,
            'location' => implode(', ', array_slice($loc, 0, 2)) ?: $i->country,
            'programs' => (int) ($i->programs_count ?? 0),
            'is_major_city' => (bool) $i->is_major_city,
            'affordability_band' => $i->affordability_band,
            'offer_tat_band' => $i->offer_tat_band,
            'vfi_represented' => (bool) $i->vfi_represented,
        ];
    }
}
