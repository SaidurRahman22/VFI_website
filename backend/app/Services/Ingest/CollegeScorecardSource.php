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
                'fields' => 'id,school.name,school.city,school.state,school.school_url,'
                    .'latest.cost.tuition.out_of_state,latest.student.size,'
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
