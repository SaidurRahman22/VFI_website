<?php

namespace App\Services\Ingest;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Phase 8C — REAL US programs from the U.S. Dept. of Education College Scorecard
 * (public-domain government data). Program-of-study rows come from
 * `latest.programs.cip_4_digit`; each CIP program becomes one Program under its
 * school. Scorecard carries no intake/deadline data, so the three standard VFI
 * intakes (Fall / Spring / Summer) are attached — the user's "3 intakes for all"
 * request. Network + key are read from config/catalogue.php.
 *
 * DEMO_KEY works but is heavily rate-limited; a free key from
 * https://api.data.gov/signup lifts it (Developer_requier.md §4C).
 */
class CollegeScorecardSource implements IngestSource
{
    /** CIP 2-digit family => our study_area taxonomy code. */
    private const CIP_AREA = [
        '11' => 'it_computing', '14' => 'engineering', '15' => 'engineering',
        '52' => 'business', '51' => 'health', '26' => 'sciences', '40' => 'sciences',
        '27' => 'sciences', '03' => 'sciences', '42' => 'social_law', '45' => 'social_law',
        '22' => 'social_law', '44' => 'social_law', '50' => 'arts_design', '23' => 'arts_design',
        '54' => 'arts_design', '24' => 'arts_design', '09' => 'arts_design', '16' => 'arts_design',
    ];

    /** Scorecard credential.level code => our level taxonomy code. */
    private const CRED_LEVEL = [
        2 => 'associate', 3 => 'bachelor', 5 => 'master', 6 => 'phd', 8 => 'pg_certificate',
    ];

    private const STEM_AREAS = ['engineering', 'it_computing', 'sciences'];

    /** Cache key holding the next page to fetch when a run hits the hourly quota. */
    private const RESUME_KEY = 'ingest:scorecard:next_page';

    private ?string $stoppedEarly = null;

    public function name(): string
    {
        return 'scorecard';
    }

    public function records(): iterable
    {
        $cfg = config('catalogue.scorecard');
        // The programs-of-study field is heavy per school AND the DEMO_KEY is
        // bandwidth-throttled (~30KB/s), so keep pages TINY so each one finishes
        // inside the timeout (a page must complete for its records to persist).
        // A real CATALOGUE_SCORECARD_KEY lifts the throttle — raise this then.
        $perPage = (int) ($cfg['per_page'] ?? 10);
        $maxInst = (int) $cfg['max_institutions'];
        $pages = (int) ceil($maxInst / $perPage);
        $baseYear = (int) config('catalogue.seed.base_year', 2026);
        $seen = 0;

        // Resume point: DEMO_KEY allows only ~30 requests/hour, so a full run can
        // span several hours. We remember the last page that fully imported and
        // continue from there on the next run (cleared once the run completes).
        $startPage = (int) Cache::get(self::RESUME_KEY, 0);
        $pages = max($pages, $startPage + 1);

        for ($page = $startPage; $page < $pages; $page++) {
            // No ->retry(): every retry burns another request against the hourly
            // quota, and a timeout here means the page was too big, not flaky.
            $resp = Http::connectTimeout(15)->timeout(120)->get($cfg['base'], [
                'api_key' => $cfg['key'],
                // Request ONLY the sub-fields we use — the full cip_4_digit object
                // carries dozens of earnings/debt fields and makes each school
                // ~700KB; this trims it ~90% so the throttle can deliver a page.
                // Every one of these is published by the U.S. Department of
                // Education and is public domain. The cost/admissions block was
                // added because the university detail page was rendering empty
                // Cost-to-study and Admissions sections while the real figures
                // sat one API field away. Still narrow on purpose: the full
                // cip_4_digit object carries dozens of earnings/debt fields and
                // makes each school ~700KB.
                'fields' => 'id,school.name,school.city,school.state,school.school_url,'
                    .'school.locale,school.ownership,school.price_calculator_url,'
                    .'latest.cost.tuition.in_state,latest.cost.tuition.out_of_state,'
                    .'latest.cost.attendance.academic_year,latest.cost.booksupply,'
                    .'latest.cost.roomboard.oncampus,latest.cost.roomboard.offcampus,'
                    .'latest.cost.avg_net_price.public,latest.cost.avg_net_price.private,'
                    .'latest.admissions.sat_scores.25th_percentile.critical_reading,'
                    .'latest.admissions.sat_scores.75th_percentile.critical_reading,'
                    .'latest.admissions.sat_scores.25th_percentile.math,'
                    .'latest.admissions.sat_scores.75th_percentile.math,'
                    .'latest.admissions.act_scores.25th_percentile.cumulative,'
                    .'latest.admissions.act_scores.75th_percentile.cumulative,'
                    .'latest.student.size,'
                    .'latest.admissions.admission_rate.overall,'
                    .'latest.completion.completion_rate_4yr_150nt,'
                    .'latest.earnings.10_yrs_after_entry.median,'
                    .'latest.programs.cip_4_digit.code,latest.programs.cip_4_digit.title,latest.programs.cip_4_digit.credential.level',
                'per_page' => $perPage,
                'page' => $page,
                'school.operating' => 1,
                'latest.student.size__range' => '2000..',   // real, sizeable institutions
                'sort' => 'latest.student.size:desc',
            ]);

            if (! $resp->successful()) {
                // Rate limited → remember where to resume; other errors → stop clean.
                if ($resp->status() === 429 || str_contains((string) $resp->body(), 'OVER_RATE_LIMIT')) {
                    Cache::put(self::RESUME_KEY, $page, now()->addDay());
                    $this->stoppedEarly = 'rate limit reached — re-run later to continue from page '.$page;
                }

                return;
            }

            $results = $resp->json('results') ?? [];
            if ($results === []) {
                break;
            }

            foreach ($results as $school) {
                if ($seen >= $maxInst) {
                    Cache::forget(self::RESUME_KEY);

                    return;
                }
                $seen++;
                yield from $this->schoolRecords($school, $baseYear);
            }

            // page fully yielded — next run may start after it
            Cache::put(self::RESUME_KEY, $page + 1, now()->addDay());
        }

        Cache::forget(self::RESUME_KEY);   // reached the end of the catalogue
    }

    /** Set when the run stopped on the hourly quota; the command reports it. */
    public function stoppedEarly(): ?string
    {
        return $this->stoppedEarly;
    }

    private function schoolRecords(array $school, int $baseYear): iterable
    {
        $name = trim((string) ($school['school.name'] ?? ''));
        $programs = $school['latest.programs.cip_4_digit'] ?? [];
        if ($name === '' || ! is_array($programs) || $programs === []) {
            return;
        }

        $city = trim((string) ($school['school.city'] ?? ''));
        $state = trim((string) ($school['school.state'] ?? ''));
        $tuitionAnnual = $school['latest.cost.tuition.out_of_state'] ?? null;
        $instRef = 'scorecard:'.($school['id'] ?? md5($name));

        // REAL public-domain figures from the feed — these fill the Overview stat
        // tiles and the Placements section so staff don't have to type them, and
        // nothing is invented. Only set when the feed actually reports a value.
        $admitRate = $school['latest.admissions.admission_rate.overall'] ?? null;
        $gradRate = $school['latest.completion.completion_rate_4yr_150nt'] ?? null;
        $earnings = $school['latest.earnings.10_yrs_after_entry.median'] ?? null;
        $size = $school['latest.student.size'] ?? null;

        $stats = [];
        if (is_numeric($admitRate)) {
            $stats[] = ['value' => round($admitRate * 100).'%', 'label' => 'Acceptance rate'];
        }
        if (is_numeric($gradRate)) {
            $stats[] = ['value' => round($gradRate * 100).'%', 'label' => 'Graduation rate'];
        }
        if (is_numeric($size)) {
            $stats[] = ['value' => number_format((int) $size), 'label' => 'Students enrolled'];
        }

        // ---- Cost to study, Admissions, and campus -------------------------
        // All of it straight from the feed and attributed on screen. Anything the
        // feed does not report is left NULL for staff to fill from the university's
        // own site — an invented tuition figure is worse than an empty row, because
        // a counsellor will quote it.
        $money = static fn ($v) => is_numeric($v) && $v > 0 ? 'USD '.number_format((int) $v) : null;

        $costRows = [];
        foreach ([
            ['latest.cost.tuition.in_state', 'Tuition (in-state)'],
            ['latest.cost.tuition.out_of_state', 'Tuition (out-of-state / international)'],
            ['latest.cost.booksupply', 'Books and supplies'],
            ['latest.cost.roomboard.oncampus', 'Room and board (on campus)'],
            ['latest.cost.roomboard.offcampus', 'Room and board (off campus)'],
            ['latest.cost.attendance.academic_year', 'Total cost of attendance'],
        ] as [$key, $label]) {
            if (($v = $money($school[$key] ?? null)) !== null) {
                $costRows[] = ['label' => $label, 'value' => $v];
            }
        }
        // The net price is what students actually pay after aid, which is the more
        // honest headline — but it is split across two columns by school type.
        $netPrice = $money($school['latest.cost.avg_net_price.public'] ?? null)
            ?? $money($school['latest.cost.avg_net_price.private'] ?? null);
        if ($netPrice !== null) {
            $costRows[] = ['label' => 'Average net price paid (after aid)', 'value' => $netPrice];
        }

        // Room and board doubles as the living-cost line: it is the only
        // accommodation figure the feed publishes, and it is a real one.
        $roomBoard = $money($school['latest.cost.roomboard.oncampus'] ?? null)
            ?? $money($school['latest.cost.roomboard.offcampus'] ?? null);

        // SAT/ACT ranges are the real admission requirement this feed carries.
        // English-test requirements (IELTS/TOEFL) are NOT published here, so
        // admission_english stays empty rather than guessed.
        $satLow = ($school['latest.admissions.sat_scores.25th_percentile.critical_reading'] ?? null)
            + ($school['latest.admissions.sat_scores.25th_percentile.math'] ?? null);
        $satHigh = ($school['latest.admissions.sat_scores.75th_percentile.critical_reading'] ?? null)
            + ($school['latest.admissions.sat_scores.75th_percentile.math'] ?? null);
        $actLow = $school['latest.admissions.act_scores.25th_percentile.cumulative'] ?? null;
        $actHigh = $school['latest.admissions.act_scores.75th_percentile.cumulative'] ?? null;

        $admission = [];
        if ($satLow > 0 && $satHigh > 0) {
            $admission[] = 'SAT '.$satLow.'–'.$satHigh.' (middle 50% of admitted students)';
        }
        if (is_numeric($actLow) && is_numeric($actHigh)) {
            $admission[] = 'ACT '.(int) $actLow.'–'.(int) $actHigh.' (middle 50%)';
        }

        // A factual sentence assembled from feed values only — no adjectives, no
        // marketing. LOCALE and OWNERSHIP are coded integers in the feed.
        $localeText = match (true) {
            in_array((int) ($school['school.locale'] ?? 0), [11, 12, 13], true) => 'in a city',
            in_array((int) ($school['school.locale'] ?? 0), [21, 22, 23], true) => 'in a suburban area',
            in_array((int) ($school['school.locale'] ?? 0), [31, 32, 33], true) => 'in a town',
            in_array((int) ($school['school.locale'] ?? 0), [41, 42, 43], true) => 'in a rural area',
            default => null,
        };
        $ownerText = match ((int) ($school['school.ownership'] ?? 0)) {
            1 => 'public',
            2 => 'private not-for-profit',
            3 => 'private for-profit',
            default => null,
        };
        $overview = null;
        if ($ownerText !== null || $localeText !== null || is_numeric($size)) {
            $overview = trim(sprintf(
                '%s is a %s institution%s%s%s. Figures on this page are published by the '
                .'U.S. Department of Education (College Scorecard).',
                $name,
                $ownerText ?? 'higher education',
                $localeText !== null ? ' '.$localeText : '',
                $city !== '' ? ' in '.$city.($state !== '' ? ', '.$state : '') : '',
                is_numeric($size) ? ', with '.number_format((int) $size).' students enrolled' : ''
            ));
        }

        $institution = [
            'name' => $name,
            'country' => 'United States',
            'province_state' => $state !== '' ? $state : null,
            'city' => $city !== '' ? $city : null,
            'is_major_city' => in_array($city, ['New York', 'Los Angeles', 'Chicago', 'Boston', 'Houston', 'Seattle', 'San Francisco', 'Washington'], true),
            'vfi_represented' => true,
            'offer_tat_band' => 'standard',
            'website' => $this->url($school['school.school_url'] ?? null),
            'overview_stats' => $stats,
            'salary_note' => is_numeric($earnings)
                ? ('USD '.number_format((int) $earnings).' median earnings 10 years after entry (U.S. Dept. of Education, College Scorecard)')
                : null,
            'placement_note' => is_numeric($earnings)
                ? ('Graduate earnings for this institution are published by the U.S. Department of Education. '
                    .'The figure below is the median for former students ten years after they first enrolled, across all programs.')
                : null,
            'overview' => $overview,
            'cost_rows' => $costRows,
            'cost_note' => $costRows === [] ? null
                : 'Published by the U.S. Department of Education (College Scorecard), most recent reporting year. '
                    .'Figures are institution-wide averages — confirm the exact cost of a specific programme with the university.',
            'living_cost_note' => $roomBoard !== null ? $roomBoard.' per year (room and board)' : null,
            'admission_academic' => $admission === [] ? null : implode(' · ', $admission),
            'external_ref' => $instRef,
        ];

        // de-dupe program-of-study by CIP+credential (Scorecard repeats them)
        $emitted = [];
        foreach ($programs as $prog) {
            $cip = (string) ($prog['code'] ?? '');
            $credLevel = (int) ($prog['credential']['level'] ?? 0);
            $level = self::CRED_LEVEL[$credLevel] ?? null;
            $title = trim((string) ($prog['title'] ?? ''));
            if ($level === null || $title === '' || $cip === '') {
                continue;
            }
            $key = $cip.':'.$credLevel;
            if (isset($emitted[$key])) {
                continue;
            }
            $emitted[$key] = true;

            $area = self::CIP_AREA[substr($cip, 0, 2)] ?? null;
            $isStem = $area !== null && in_array($area, self::STEM_AREAS, true);

            yield [
                'institution' => $institution,
                'program' => [
                    'title' => ucwords(strtolower($title)),
                    'level' => $level,
                    'study_area' => $area,
                    'discipline_area' => ucwords(strtolower($title)),
                    'duration_band' => in_array($level, ['master', 'pg_certificate'], true) ? '1_5yr' : ($level === 'phd' ? '4yr_plus' : '4yr_plus'),
                    'tuition_fee_minor' => is_numeric($tuitionAnnual) ? (int) round($tuitionAnnual * 100) : null,
                    'tuition_currency' => 'USD',
                    // Scorecard has no per-programme price: the only tuition it
                    // publishes is `latest.cost.tuition.out_of_state`, one annual
                    // figure for the whole school, which is why every programme
                    // here carries the same number. Declaring the basis is what
                    // stops the search card presenting it as this course's fee.
                    'tuition_basis' => 'institution_average',
                    'is_stem' => $isStem,
                    'is_open' => true,
                    'external_ref' => 'scorecard:'.($school['id'] ?? md5($name)).':'.$key,
                ],
                'intakes' => $this->standardIntakes($baseYear),
                'requirements' => [], // Scorecard carries no test data — leave honest/empty
            ];
        }
    }

    /** Normalise the feed's school URL into a storable absolute URL. */
    private function url(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }

        return filter_var($raw, FILTER_VALIDATE_URL) ? mb_substr($raw, 0, 190) : null;
    }

    /** The three standard VFI intakes (no deadline data in the feed). */
    private function standardIntakes(int $baseYear): array
    {
        return [
            ['month' => 9, 'year' => $baseYear, 'season' => 'fall', 'deadline' => null, 'status' => 'open'],
            ['month' => 1, 'year' => $baseYear + 1, 'season' => 'spring', 'deadline' => null, 'status' => 'open'],
            ['month' => 5, 'year' => $baseYear + 1, 'season' => 'summer', 'deadline' => null, 'status' => 'open'],
        ];
    }
}
