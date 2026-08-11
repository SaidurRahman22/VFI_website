<?php

namespace App\Services\Ingest;

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

    public function name(): string
    {
        return 'scorecard';
    }

    public function records(): iterable
    {
        $cfg = config('catalogue.scorecard');
        // The programs-of-study field is heavy per school, so keep pages SMALL —
        // 100/page overran the 30s network limit on the VPS. 25/page + a longer
        // timeout completes reliably.
        $perPage = 25;
        $maxInst = (int) $cfg['max_institutions'];
        $pages = (int) ceil($maxInst / $perPage);
        $baseYear = (int) config('catalogue.seed.base_year', 2026);
        $seen = 0;

        for ($page = 0; $page < $pages; $page++) {
            $resp = Http::retry(2, 800)->connectTimeout(15)->timeout(90)->get($cfg['base'], [
                'api_key' => $cfg['key'],
                'fields' => 'id,school.name,school.city,school.state,latest.cost.tuition.out_of_state,latest.programs.cip_4_digit',
                'per_page' => $perPage,
                'page' => $page,
                'school.operating' => 1,
                'latest.student.size__range' => '2000..',   // real, sizeable institutions
                'sort' => 'latest.student.size:desc',
            ]);

            if (! $resp->successful()) {
                // rate-limit / transient — stop cleanly, keep what we have
                break;
            }

            $results = $resp->json('results') ?? [];
            if ($results === []) {
                break;
            }

            foreach ($results as $school) {
                if ($seen >= $maxInst) {
                    return;
                }
                $seen++;
                yield from $this->schoolRecords($school, $baseYear);
            }
        }
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

        $institution = [
            'name' => $name,
            'country' => 'United States',
            'province_state' => $state !== '' ? $state : null,
            'city' => $city !== '' ? $city : null,
            'is_major_city' => in_array($city, ['New York', 'Los Angeles', 'Chicago', 'Boston', 'Houston', 'Seattle', 'San Francisco', 'Washington'], true),
            'vfi_represented' => true,
            'offer_tat_band' => 'standard',
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
