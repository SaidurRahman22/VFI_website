<?php

namespace App\Services\Ingest;

/**
 * Phase 8C — a realistic, DETERMINISTIC synthetic catalogue for the destinations
 * with no free program feed (UK, Canada, Australia, Ireland, New Zealand). Every
 * row is flagged `source = seed` so it is unmistakable placeholder data and swaps
 * out for a licensed feed with no code change (Developer_requier.md §4). No
 * randomness — the same seed produces the same catalogue every run.
 */
class SeedSource implements IngestSource
{
    /** @var array<string, list<string>> country => representative cities */
    private const CITIES = [
        'United Kingdom' => ['London', 'Manchester', 'Birmingham', 'Leeds', 'Glasgow', 'Bristol', 'Sheffield', 'Nottingham'],
        'Canada' => ['Toronto', 'Vancouver', 'Montreal', 'Calgary', 'Ottawa', 'Waterloo', 'Halifax', 'Winnipeg'],
        'Australia' => ['Sydney', 'Melbourne', 'Brisbane', 'Perth', 'Adelaide', 'Canberra', 'Gold Coast', 'Wollongong'],
        'Ireland' => ['Dublin', 'Cork', 'Galway', 'Limerick', 'Maynooth', 'Waterford', 'Sligo', 'Dundalk'],
        'New Zealand' => ['Auckland', 'Wellington', 'Christchurch', 'Hamilton', 'Dunedin', 'Palmerston North', 'Tauranga', 'Nelson'],
    ];

    private const LEVELS = ['bachelor', 'bachelor_honours', 'pg_diploma', 'master', 'mba', 'phd'];

    /** study_area => [discipline codes, program-noun] */
    private const AREAS = [
        'business' => ['finance', 'marketing', 'economics'],
        'engineering' => ['civil', 'mechanical', 'electrical'],
        'it_computing' => ['data_science', 'ai_ml', 'software', 'cybersecurity'],
        'health' => ['nursing', 'public_health', 'pharmacy'],
        'sciences' => ['agriculture', 'psychology'],
        'social_law' => ['law', 'economics'],
    ];

    private const DISCIPLINE_TITLES = [
        'finance' => 'Finance', 'marketing' => 'Marketing', 'economics' => 'Economics',
        'civil' => 'Civil Engineering', 'mechanical' => 'Mechanical Engineering', 'electrical' => 'Electrical Engineering',
        'data_science' => 'Data Science', 'ai_ml' => 'Artificial Intelligence', 'software' => 'Software Engineering',
        'cybersecurity' => 'Cybersecurity', 'nursing' => 'Nursing', 'public_health' => 'Public Health',
        'pharmacy' => 'Pharmacy', 'agriculture' => 'Agriculture', 'psychology' => 'Psychology', 'law' => 'Law',
    ];

    private const LEVEL_TITLE = [
        'bachelor' => 'BSc', 'bachelor_honours' => 'BSc (Hons)', 'pg_diploma' => 'PG Diploma in',
        'master' => 'MSc', 'mba' => 'MBA', 'phd' => 'PhD',
    ];

    private const STEM = ['engineering', 'it_computing', 'sciences'];

    public function name(): string
    {
        return 'seed';
    }

    public function records(): iterable
    {
        $unisPer = (int) config('catalogue.seed.universities_per_country', 6);
        $progsPer = (int) config('catalogue.seed.programs_per_university', 8);
        $baseYear = (int) config('catalogue.seed.base_year', 2026);
        $areaKeys = array_keys(self::AREAS);

        foreach (self::CITIES as $country => $cities) {
            for ($u = 0; $u < $unisPer; $u++) {
                $city = $cities[$u % count($cities)];
                $uniName = $city.' '.(['University', 'Metropolitan University', 'City University', 'Institute of Technology'][$u % 4]);
                $uref = 'seed:'.substr(md5($country.$uniName), 0, 16);

                $institution = [
                    'name' => $uniName, 'country' => $country, 'city' => $city,
                    'is_major_city' => $u < 3, 'has_own_english_test' => ($u % 2) === 0,
                    'offer_tat_band' => ['fast', 'standard', 'slow'][$u % 3],
                    'offer_acceptance_band' => ['high', 'medium', 'low'][$u % 3],
                    'affordability_band' => ['low', 'medium', 'high'][($u + 1) % 3],
                    'tuition_deposit_policy' => ['none', 'low', 'standard'][$u % 3],
                    'interview_required' => ($u % 4) === 3,
                    'vfi_represented' => true, 'external_ref' => $uref,
                ];

                for ($p = 0; $p < $progsPer; $p++) {
                    $area = $areaKeys[$p % count($areaKeys)];
                    $disciplines = self::AREAS[$area];
                    $disc = $disciplines[$p % count($disciplines)];
                    $level = self::LEVELS[($u + $p) % count(self::LEVELS)];
                    $title = self::LEVEL_TITLE[$level].' '.($level === 'mba' ? '('.self::DISCIPLINE_TITLES[$disc].')' : self::DISCIPLINE_TITLES[$disc]);
                    $isStem = in_array($area, self::STEM, true);
                    $pref = 'seed:'.substr(md5($uref.$title.$p), 0, 20);

                    // fee scales a little by country + level, in minor units (cents)
                    $baseFee = ['United Kingdom' => 18000, 'Canada' => 22000, 'Australia' => 30000, 'Ireland' => 16000, 'New Zealand' => 28000][$country] ?? 20000;
                    $fee = ($baseFee + ($level === 'master' || $level === 'mba' ? 4000 : 0) + $p * 250) * 100;

                    $program = [
                        'title' => $title, 'level' => $level, 'study_area' => $area,
                        'discipline_area' => self::DISCIPLINE_TITLES[$disc], 'duration_band' => $level === 'phd' ? '3yr' : ($level === 'bachelor' ? '3yr' : '1yr'),
                        'esl_elp_available' => ($p % 2) === 0, 'tuition_fee_minor' => $fee, 'tuition_currency' => 'USD',
                        'application_fee_minor' => (($p % 3) === 0 ? 0 : 7500), 'application_fee_currency' => 'USD',
                        'is_stem' => $isStem, 'has_coop_internship' => ($p % 3) === 1,
                        'scholarship_available' => ($p % 2) === 0, 'application_fee_waiver' => ($p % 3) === 0,
                        'moi_acceptable' => ($u % 2) === 0, 'job_demand_band' => ['high', 'medium'][$p % 2],
                        'is_open' => true, 'external_ref' => $pref,
                    ];

                    // three intakes across this + next year (some past → tests staleness)
                    $intakes = [
                        ['month' => 9, 'year' => $baseYear, 'season' => 'fall', 'deadline' => ($baseYear).'-07-01', 'status' => 'open'],
                        ['month' => 1, 'year' => $baseYear + 1, 'season' => 'spring', 'deadline' => ($baseYear).'-11-01', 'status' => 'open'],
                        ['month' => 5, 'year' => $baseYear + 1, 'season' => 'summer', 'deadline' => ($baseYear + 1).'-03-01', 'status' => 'open'],
                    ];

                    $requirements = [
                        ['test' => 'ielts', 'min_overall' => $level === 'phd' ? 7.0 : 6.5, 'is_required' => true, 'waiver_available' => $program['moi_acceptable']],
                    ];
                    if ($level === 'master' || $level === 'mba') {
                        $requirements[] = ['test' => 'gre', 'min_overall' => null, 'is_required' => ($p % 4) === 0, 'waiver_available' => ($p % 2) === 0];
                    }
                    if ($level === 'mba') {
                        $requirements[] = ['test' => 'gmat', 'min_overall' => 600, 'is_required' => false, 'waiver_available' => true];
                    }

                    yield compact('institution', 'program', 'intakes', 'requirements');
                }
            }
        }
    }
}
