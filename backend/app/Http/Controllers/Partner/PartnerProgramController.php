<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramSearchRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        /*
         * $query stays FILTERS ONLY from here down, and carries no ORDER BY.
         *
         * It used to end with three order clauses - the seed CASE, the sort
         * column, then id - and the grouped query below is a clone of it. A
         * clone carrying `order by case when source = 'seed' …` into a
         * `group by program_id` orders on a column that is not grouped and not
         * aggregated: SQLite shrugs and picks a row, Postgres refuses the
         * statement outright. The suite would have stayed green and production
         * would have 500'd on every search.
         *
         * Every one of those orderings now has an aggregate form on $grouped,
         * where it belongs.
         */
        [$col, $dir] = self::SORTS[$data['sort'] ?? 'deadline'];

        /*
         * ONE CARD PER PROGRAMME, not one per intake.
         *
         * program_search holds one row per program-intake (SearchIndexer fans
         * out over $program->intakes and repeats every descriptive field), and
         * this method used to paginate those rows. A Master's at Kiel with Fall
         * 2026, Spring 2027 and Summer 2027 therefore arrived as three
         * identical cards differing only in a date. An earlier pass noticed and
         * fixed only the COUNT - returning meta.programs beside meta.total so
         * the page would stop claiming 123,621 programmes for a catalogue of
         * 41,287 - and left the list itself exploded.
         *
         * Two phases, because a GROUP BY cannot also carry the per-intake rows:
         *
         *   1. Group the FILTERED set by program_id to get one page of
         *      programme ids in the right order. The sort keys live on the
         *      intake row, so each becomes an aggregate: the soonest deadline,
         *      the lowest tuition, the fastest offer. That is also the honest
         *      reading - "sort by deadline" means the next one a student could
         *      actually catch.
         *   2. Re-read every row for those ids THROUGH THE SAME FILTERS, so a
         *      search for Fall shows a card listing Fall only. Showing all of a
         *      programme's intakes here would answer a question nobody asked.
         *
         * Both phases are plain aggregate SQL - min()/max() over a CASE - so
         * Postgres 16 and the SQLite the suite runs on agree. This project has
         * already shipped a query that passed on SQLite and silently refused
         * every row under Postgres RLS; that is not a mistake worth repeating.
         */
        $grouped = (clone $query)
            ->selectRaw('program_id')
            ->selectRaw("min(case when source = 'seed' then 1 else 0 end) as seed_rank")
            ->groupBy('program_id');

        /*
         * Sample rows go behind the real catalogue, ALWAYS, whatever the sort.
         * `seed` is fabricated data standing in for the destinations with no
         * licensed feed yet (UK/CA/AU/IE/NZ); the real feeds are US Scorecard
         * and DAAD. There are only 240 seed rows against 41,000 real ones, but
         * they carry the nearest deadlines, so a deadline sort put every one of
         * them ahead of the whole real catalogue - page one of Search was
         * nothing but sample data. They stay searchable (dropping them leaves
         * five destinations with no programmes at all) and the card keeps its
         * "Sample data" badge; they simply must not lead.
         *
         * min() over the CASE, not the bare column: a programme is real if ANY
         * of its rows is, and min picks 0 over 1. Expressed as an aggregate so
         * it is legal beside the GROUP BY on both drivers.
         */
        $grouped->orderBy('seed_rank', 'asc');

        if ($col !== 'id') {
            // The aggregate matching the direction: soonest deadline, cheapest
            // tuition, fastest offer for 'asc'; the largest for 'desc'. Aliased
            // so the ORDER BY can name it on both drivers.
            $agg = $dir === 'desc' ? 'max' : 'min';
            $grouped->selectRaw("{$agg}({$col}) as sort_key")
                ->orderByRaw('sort_key is null')
                ->orderBy('sort_key', $dir);
        }
        // 'newest' sorts on the row id; the newest row a programme owns is its
        // newest intake, which is the same ordering the un-grouped query gave.
        $grouped->selectRaw('max(id) as newest_row')->orderBy('newest_row', 'desc');

        $page = $grouped->paginate($data['per_page'] ?? 24)->withQueryString();

        // getCollection() keeps the paginator's order; pluck would too, but this
        // reads as what it is.
        $ids = $page->getCollection()->pluck('program_id')->all();

        $rowsByProgram = $ids === []
            ? collect()
            : (clone $query)->whereIn('program_id', $ids)->get()->groupBy('program_id');

        return response()->json([
            'data' => collect($ids)->map(
                fn ($id) => $this->presentProgram($rowsByProgram->get($id) ?? collect())
            )->filter()->values(),
            'meta' => [
                // Both numbers now mean the same thing, because a row in `data`
                // IS a programme. `programs` is kept so the page that reads it
                // does not break, and because naming it is cheaper than making
                // every caller remember which one it wanted.
                'total' => $page->total(),
                'programs' => $page->total(),
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
            // see presentProgram(): the detail panel is what a counsellor reads
            // immediately before quoting a fee, so it needs the basis too
            'tuition' => $p->tuition_fee_minor !== null
                ? ['minor' => $p->tuition_fee_minor, 'currency' => $p->tuition_currency, 'basis' => $p->tuition_basis]
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
                ? ['minor' => $p->tuition_fee_minor, 'currency' => $p->tuition_currency, 'basis' => $p->tuition_basis]
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

    /**
     * One programme, carrying every intake that matched the search.
     *
     * The descriptive fields are identical across a programme's rows by
     * construction - SearchIndexer hoists them outside the per-intake loop - so
     * the first row speaks for all of them. Only the intake, the deadline and
     * the staleness differ, and those are the three this returns per intake
     * rather than per card.
     *
     * `is_stale` is per INTAKE, deliberately. A programme with one expired and
     * two live intakes is not stale, but the expired one must still say so, or
     * a counsellor picks a date that has already gone. Collapsing it to one flag
     * on the card is how the old "Deadline passed" badge would have quietly
     * stopped appearing.
     *
     * @param  Collection<int, ProgramSearchRow>  $rows
     */
    private function presentProgram($rows): ?array
    {
        $first = $rows->first();
        if (! $first instanceof ProgramSearchRow) {
            return null;   // a programme whose rows vanished between the two reads
        }

        $intakes = $rows
            ->sortBy([['intake_year', 'asc'], ['intake_month', 'asc']])
            ->map(fn (ProgramSearchRow $r) => [
                // row_id, because the shortlist and the apply flow identify an
                // intake, and program_id alone no longer does.
                'row_id' => $r->id,
                'month' => $r->intake_month,
                'year' => $r->intake_year,
                'season' => $r->season_label,
                'deadline' => optional($r->application_deadline_at)->toDateString(),
                'is_stale' => (bool) $r->is_stale,
            ])->values()->all();

        return [
            'id' => $first->id,              // kept: the first matching row
            'program_id' => $first->program_id,
            'title' => $first->title,
            'university' => $first->university_name,
            'country' => $first->country,
            'province_state' => $first->province_state,
            'level' => $first->level,
            'study_area' => $first->study_area,
            'discipline_area' => $first->discipline_area,
            'duration_band' => $first->duration_band,
            'tuition' => $first->tuition_fee_minor !== null
                ? ['minor' => $first->tuition_fee_minor, 'currency' => $first->tuition_currency, 'basis' => $first->tuition_basis]
                : null,
            'intakes' => $intakes,
            // The soonest deadline across the matching intakes - the one a
            // student could still catch - so the card's single line agrees with
            // the deadline sort above it.
            'deadline' => collect($intakes)->pluck('deadline')->filter()->sort()->first(),
            'offer_tat_days' => $first->offer_tat_days,
            'badges' => array_values(array_filter(explode(' ', trim((string) $first->flags)))),
            'source' => $first->source,
            // True only when EVERY matching intake is stale; the per-intake flag
            // above is what a date picker reads.
            'is_stale' => $rows->every(fn (ProgramSearchRow $r) => (bool) $r->is_stale),
        ];
    }
}
