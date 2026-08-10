<?php

namespace App\Services\Ingest;

use Illuminate\Support\Facades\Http;

/**
 * Phase 8C — REAL German programs from the DAAD "International Programmes in
 * Germany" open API (English-taught degrees). One course = one Program. DAAD
 * lists the start semester but not per-year deadlines, so the three standard VFI
 * intakes are attached (the user's "3 intakes for all" request). Field names
 * vary across the feed, so every read has fallbacks and unparsable rows are
 * skipped rather than guessed — the ingest command reports the skip count so the
 * mapping can be tuned against the live response.
 */
class DaadSource implements IngestSource
{
    private const STEM_KEYS = ['engineer', 'comput', 'informat', 'data', 'mathemat', 'physics', 'technolog', 'science'];

    public function name(): string
    {
        return 'daad';
    }

    public function records(): iterable
    {
        $cfg = config('catalogue.daad');
        $max = (int) $cfg['max'];
        $perPage = 100;
        $pages = (int) ceil($max / $perPage);
        $baseYear = (int) config('catalogue.seed.base_year', 2026);
        $seen = 0;

        for ($page = 1; $page <= $pages; $page++) {
            $resp = Http::retry(2, 500)->timeout(30)->get($cfg['base'], [
                'cert' => '', 'admissionSemester' => '', 'scholarship' => '', 'langDeu' => '',
                'langEng' => 8,      // English-taught
                'fos' => '', 'degree' => '', 'q' => '',
                'sort' => 4, 'page' => $page, 'display' => $perPage,
            ]);

            if (! $resp->successful()) {
                break;
            }

            $courses = $resp->json('courses') ?? $resp->json('results') ?? [];
            if (! is_array($courses) || $courses === []) {
                break;
            }

            foreach ($courses as $course) {
                if ($seen >= $max) {
                    return;
                }
                $rec = $this->toRecord($course, $baseYear);
                if ($rec !== null) {
                    $seen++;
                    yield $rec;
                }
            }
        }
    }

    private function toRecord(array $c, int $baseYear): ?array
    {
        $title = trim((string) ($c['courseName'] ?? $c['name'] ?? ''));
        $uni = trim((string) ($c['academy'] ?? $c['university'] ?? $c['institution'] ?? ''));
        if ($title === '' || $uni === '') {
            return null;
        }

        $level = $this->level((string) ($c['degree'] ?? $c['courseType'] ?? ''), $title);
        if ($level === null) {
            return null; // don't guess a degree level on real data
        }

        $city = trim((string) ($c['city'] ?? ''));
        $id = $c['id'] ?? $c['courseId'] ?? md5($uni.$title);
        $tuition = $this->tuitionMinor($c['tuitionFees'] ?? $c['tuition'] ?? null);
        $isStem = $this->looksStem($title);

        return [
            'institution' => [
                'name' => $uni,
                'country' => 'Germany',
                'city' => $city !== '' ? $city : null,
                'is_major_city' => in_array($city, ['Berlin', 'Munich', 'München', 'Hamburg', 'Frankfurt', 'Cologne', 'Köln', 'Stuttgart'], true),
                'has_own_english_test' => false,
                'affordability_band' => 'low',        // German public unis: little/no tuition
                'tuition_deposit_policy' => 'none',
                'vfi_represented' => true,
                'offer_tat_band' => 'standard',
                'external_ref' => 'daad:inst:'.md5($uni),
            ],
            'program' => [
                'title' => $title,
                'level' => $level,
                'study_area' => null, // DAAD subject is free text — left soft, not force-mapped
                'discipline_area' => trim((string) ($c['subject'] ?? '')) ?: null,
                'duration_band' => $this->duration((string) ($c['programmeDuration'] ?? $c['duration'] ?? '')),
                'tuition_fee_minor' => $tuition,
                'tuition_currency' => 'EUR',
                'application_fee_minor' => 0,
                'is_stem' => $isStem,
                'scholarship_available' => ! empty($c['scholarship']),
                'is_open' => true,
                'external_ref' => 'daad:'.$id,
            ],
            'intakes' => [
                ['month' => 9, 'year' => $baseYear, 'season' => 'fall', 'deadline' => null, 'status' => 'open'],
                ['month' => 1, 'year' => $baseYear + 1, 'season' => 'spring', 'deadline' => null, 'status' => 'open'],
                ['month' => 5, 'year' => $baseYear + 1, 'season' => 'summer', 'deadline' => null, 'status' => 'open'],
            ],
            'requirements' => [
                ['test' => 'ielts', 'min_overall' => 6.5, 'is_required' => true, 'waiver_available' => false],
            ],
        ];
    }

    private function level(string $degree, string $title): ?string
    {
        $s = strtolower($degree.' '.$title);

        return match (true) {
            str_contains($s, 'phd') || str_contains($s, 'doctor') || str_contains($s, 'dr.') => 'phd',
            str_contains($s, 'mba') => 'mba',
            str_contains($s, 'master') || str_contains($s, 'm.sc') || str_contains($s, 'msc') || str_contains($s, 'm.a') || str_contains($s, 'm.eng') => 'master',
            str_contains($s, 'bachelor') || str_contains($s, 'b.sc') || str_contains($s, 'bsc') || str_contains($s, 'b.a') || str_contains($s, 'b.eng') => 'bachelor',
            default => null,
        };
    }

    private function looksStem(string $title): bool
    {
        $t = strtolower($title);
        foreach (self::STEM_KEYS as $k) {
            if (str_contains($t, $k)) {
                return true;
            }
        }

        return false;
    }

    private function duration(string $raw): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
            $months = str_contains(strtolower($raw), 'month') ? (float) $m[1] : (float) $m[1] * 12;

            return match (true) {
                $months < 12 => 'lt_1yr',
                $months <= 15 => '1yr',
                $months <= 20 => '1_5yr',
                $months <= 30 => '2yr',
                $months <= 42 => '3yr',
                default => '4yr_plus',
            };
        }

        return null;
    }

    private function tuitionMinor($raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_numeric($raw)) {
            return (int) round(((float) $raw) * 100);
        }
        $s = strtolower((string) $raw);
        if (str_contains($s, 'none') || str_contains($s, 'no tuition') || str_contains($s, 'free')) {
            return 0;
        }
        if (preg_match('/([\d.,]+)/', $s, $m)) {
            $num = (float) str_replace(',', '', $m[1]);

            return (int) round($num * 100);
        }

        return null;
    }
}
