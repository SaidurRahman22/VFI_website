<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramSearchRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 8D — program search + detail over the flat `program_search` table
 * (docs §4). PUBLIC reference data (no PII, no tenant scope) but console-only,
 * so it lives behind auth:web + EnsurePartner and a per-partner rate limit.
 *
 * Security: free text is a single bound LIKE on the lowercased blob; every facet
 * is validated against a fixed token allow-list and bound as '% token %' (a feed
 * value can never reach SQL); every scalar filter is a bound where; sort/order
 * come only from a fixed map (no user string ever touches an ORDER BY). Stale
 * (past-deadline/closed) intakes are hidden unless explicitly requested.
 */
class PartnerProgramController extends Controller
{
    /** The ~32 boolean facet tokens (mirror of SearchIndexer::flagsFor). */
    private const FACETS = [
        // program
        'stem', 'coop', 'scholarship', 'fee_waiver', 'moi', 'esl', 'open', 'no_app_fee',
        // institution
        'major_city', 'own_english', 'vfi', 'interview_required', 'no_interview',
        'fast_offer', 'high_acceptance', 'high_job_demand', 'affordable', 'low_deposit',
        // required tests / maths
        'req_ielts', 'req_toefl', 'req_pte', 'req_duolingo', 'req_gre', 'req_gmat', 'req_maths',
        // waivers (the negative filters)
        'waive_ielts', 'waive_toefl', 'waive_pte', 'waive_duolingo', 'waive_gre',
        'waive_gmat', 'waive_english', 'waive_maths',
    ];

    /** sort key => [column, direction]. Applied nulls-last; id is the tiebreaker. */
    private const SORTS = [
        'deadline' => ['application_deadline_at', 'asc'],
        'tuition_asc' => ['tuition_fee_minor', 'asc'],
        'tuition_desc' => ['tuition_fee_minor', 'desc'],
        'fastest_offer' => ['offer_tat_days', 'asc'],
        'newest' => ['id', 'desc'],
    ];

    /** GET /api/partner/programs/search */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:90'],
            'level' => ['nullable', 'string', 'max:60'],
            'levels' => ['nullable', 'array', 'max:20'],
            'levels.*' => ['string', 'max:60'],
            'study_area' => ['nullable', 'string', 'max:60'],
            'duration_band' => ['nullable', 'string', 'max:30'],
            'intake' => ['nullable', 'string', 'max:20'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'tuition_max' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'offer_tat_max' => ['nullable', 'integer', 'min:0', 'max:400'],
            'facets' => ['nullable', 'array', 'max:40'],
            'facets.*' => ['string', Rule::in(self::FACETS)],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'include_stale' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ProgramSearchRow::query();

        if (empty($data['include_stale'])) {
            $query->where('is_stale', false);
        }

        if (($kw = trim((string) ($data['q'] ?? ''))) !== '') {
            $query->where('search_blob', 'like', '%'.mb_strtolower($kw).'%');
        }

        foreach (['country' => 'country', 'study_area' => 'study_area', 'duration_band' => 'duration_band'] as $param => $col) {
            if (! empty($data[$param])) {
                $query->where($col, $data[$param]);
            }
        }
        // level accepts a single value or a set (the UI's level checkboxes)
        $levels = array_values(array_unique(array_filter(array_merge(
            ! empty($data['level']) ? [$data['level']] : [],
            $data['levels'] ?? []
        ))));
        if ($levels !== []) {
            $query->whereIn('level', $levels);
        }
        if (! empty($data['intake'])) {
            $query->where('season_label', $data['intake']);
        }
        if (! empty($data['year'])) {
            $query->where('intake_year', (int) $data['year']);
        }
        if (isset($data['tuition_max'])) {
            $query->whereNotNull('tuition_fee_minor')->where('tuition_fee_minor', '<=', (int) $data['tuition_max']);
        }
        if (isset($data['offer_tat_max'])) {
            $query->whereNotNull('offer_tat_days')->where('offer_tat_days', '<=', (int) $data['offer_tat_max']);
        }

        // Facets: allow-listed tokens only, each a bound '% token %' match.
        foreach (array_unique($data['facets'] ?? []) as $token) {
            $query->where('flags', 'like', '% '.$token.' %');
        }

        // Sample rows go behind the real catalogue, ALWAYS, whatever the sort.
        // `seed` is fabricated data standing in for the destinations that have no
        // licensed feed yet (UK/CA/AU/IE/NZ); the real feeds are US Scorecard and
        // DAAD. There are only 240 seed rows against 41,000 real ones, but they
        // carry the nearest deadlines, so the default deadline sort put every one
        // of them ahead of the entire real catalogue — page one of Search was
        // nothing but sample data. They stay searchable (dropping them would
        // leave five destinations with no programmes at all) and the card keeps
        // its "Sample data" badge; they simply must not lead. Written as a CASE
        // so it sorts identically on Postgres and SQLite.
        $query->orderByRaw("case when source = 'seed' then 1 else 0 end asc");

        [$col, $dir] = self::SORTS[$data['sort'] ?? 'deadline'];
        if ($col !== 'id') {
            // nulls-last (portable: the boolean sorts false<true), then value, then id
            $query->orderByRaw($col.' is null')->orderBy($col, $dir);
        }
        $query->orderBy('id', 'desc');

        // Cloned BEFORE paginate(): paginate() puts its own limit/offset on the
        // builder, and counting through that would report a page, not the set.
        $distinctPrograms = (clone $query)->distinct()->count('program_id');

        $page = $query->paginate($data['per_page'] ?? 24)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ProgramSearchRow $r) => $this->presentRow($r)),
            'meta' => [
                // A row is one programme INTAKE, not one programme — a programme
                // with three intakes owns three rows. Reporting the row count as
                // "programmes" told the partner there were 123,621 when the
                // catalogue holds 41,287, so both numbers are returned and named
                // for what they are.
                'total' => $page->total(),
                'programs' => $distinctPrograms,
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/programs/compare?ids=1,2,3 — up to 4 programs side by side. */
    public function compare(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->take(4)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['message' => 'Provide 1–4 program ids via ?ids='], 422)->header('Cache-Control', 'no-store');
        }

        $programs = Program::with(['institution', 'intakes', 'requirements'])
            ->whereIn('id', $ids->all())->get();

        // preserve the caller's order
        $ordered = $ids->map(fn ($id) => $programs->firstWhere('id', $id))->filter();

        return response()->json([
            'data' => $ordered->map(fn (Program $p) => $this->compareRow($p))->values(),
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/programs/{program} — full detail (public reference data). */
    public function show(int $program): JsonResponse
    {
        $p = Program::with(['institution', 'intakes', 'requirements', 'labels'])->find($program);
        if (! $p || ! $p->institution) {
            return response()->json(['message' => 'Program not found.'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json(['program' => [
            'id' => $p->id,
            'title' => $p->title,
            'level' => $p->level,
            'study_area' => $p->study_area,
            'discipline_area' => $p->discipline_area,
            'duration_band' => $p->duration_band,
            'tuition' => $p->tuition_fee_minor !== null
                ? ['minor' => $p->tuition_fee_minor, 'currency' => $p->tuition_currency]
                : null,
            'application_fee' => $p->application_fee_minor !== null
                ? ['minor' => $p->application_fee_minor, 'currency' => $p->application_fee_currency]
                : null,
            'is_stem' => $p->is_stem,
            'has_coop_internship' => $p->has_coop_internship,
            'scholarship_available' => $p->scholarship_available,
            'application_fee_waiver' => $p->application_fee_waiver,
            'moi_acceptable' => $p->moi_acceptable,
            'esl_elp_available' => $p->esl_elp_available,
            'is_open' => $p->is_open,
            'source' => $p->source,
            'institution' => [
                'id' => $p->institution->id,
                'name' => $p->institution->name,
                'country' => $p->institution->country,
                'province_state' => $p->institution->province_state,
                'city' => $p->institution->city,
                'is_major_city' => $p->institution->is_major_city,
                'has_own_english_test' => $p->institution->has_own_english_test,
                'offer_tat_band' => $p->institution->offer_tat_band,
                'offer_acceptance_band' => $p->institution->offer_acceptance_band,
                'affordability_band' => $p->institution->affordability_band,
                'interview_required' => $p->institution->interview_required,
                'vfi_represented' => $p->institution->vfi_represented,
            ],
            'intakes' => $p->intakes->map(fn ($i) => [
                'month' => $i->intake_month,
                'year' => $i->intake_year,
                'season' => $i->season_label,
                'deadline' => optional($i->application_deadline_at)->toDateString(),
                'status' => $i->status,
            ])->values(),
            'requirements' => $p->requirements->map(fn ($r) => [
                'test' => $r->test,
                'min_overall' => $r->min_overall,
                'is_required' => $r->is_required,
                'waiver_available' => $r->waiver_available,
                'maths_required' => $r->maths_required,
            ])->values(),
            'labels' => $p->labels->map(fn ($l) => ['code' => $l->code, 'label' => $l->label])->values(),
        ]])->header('Cache-Control', 'no-store');
    }

    /** Compact, aligned view for the compare grid. */
    private function compareRow(Program $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'university' => $p->institution?->name,
            'country' => $p->institution?->country,
            'level' => $p->level,
            'study_area' => $p->study_area,
            'discipline_area' => $p->discipline_area,
            'duration_band' => $p->duration_band,
            'tuition' => $p->tuition_fee_minor !== null
                ? ['minor' => $p->tuition_fee_minor, 'currency' => $p->tuition_currency]
                : null,
            'application_fee' => $p->application_fee_minor !== null
                ? ['minor' => $p->application_fee_minor, 'currency' => $p->application_fee_currency]
                : null,
            'is_stem' => $p->is_stem,
            'has_coop_internship' => $p->has_coop_internship,
            'scholarship_available' => $p->scholarship_available,
            'moi_acceptable' => $p->moi_acceptable,
            'interview_required' => $p->institution?->interview_required,
            'intakes' => $p->intakes->map(fn ($i) => [
                'season' => $i->season_label, 'year' => $i->intake_year,
                'deadline' => optional($i->application_deadline_at)->toDateString(),
            ])->values(),
            'requirements' => $p->requirements->map(fn ($r) => [
                'test' => $r->test, 'min_overall' => $r->min_overall,
                'is_required' => $r->is_required, 'waiver_available' => $r->waiver_available,
            ])->values(),
        ];
    }

    private function presentRow(ProgramSearchRow $r): array
    {
        return [
            'id' => $r->id,
            'program_id' => $r->program_id,
            'title' => $r->title,
            'university' => $r->university_name,
            'country' => $r->country,
            'province_state' => $r->province_state,
            'level' => $r->level,
            'study_area' => $r->study_area,
            'discipline_area' => $r->discipline_area,
            'duration_band' => $r->duration_band,
            'tuition' => $r->tuition_fee_minor !== null
                ? ['minor' => $r->tuition_fee_minor, 'currency' => $r->tuition_currency]
                : null,
            'intake' => ['month' => $r->intake_month, 'year' => $r->intake_year, 'season' => $r->season_label],
            'deadline' => optional($r->application_deadline_at)->toDateString(),
            'offer_tat_days' => $r->offer_tat_days,
            'badges' => array_values(array_filter(explode(' ', trim((string) $r->flags)))),
            'source' => $r->source,
            'is_stale' => $r->is_stale,
        ];
    }
}
