<?php

namespace Database\Seeders;

use App\Models\TaxonomyTerm;
use Illuminate\Database\Seeder;

/**
 * Phase 8B — the single served vocabulary (docs §1). These `kind` groups replace
 * the five divergent hardcoded option lists across partner-search.html,
 * js/portal.js, partner-students.html, partner-interview.html and
 * partner-dashboard.html. Idempotent (upsert on kind+value).
 */
class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::VOCABULARIES as $kind => $terms) {
            $pos = 0;
            foreach ($terms as $value => $label) {
                TaxonomyTerm::updateOrCreate(
                    ['kind' => $kind, 'value' => $value],
                    ['label' => $label, 'position' => $pos++, 'active' => true],
                );
            }
        }
    }

    private const VOCABULARIES = [
        // destination countries (real: US, DE; seeded: the rest of VFI's markets)
        'country' => [
            'United States' => 'United States', 'United Kingdom' => 'United Kingdom',
            'Canada' => 'Canada', 'Australia' => 'Australia', 'Ireland' => 'Ireland',
            'New Zealand' => 'New Zealand', 'Germany' => 'Germany', 'Netherlands' => 'Netherlands',
            'France' => 'France', 'Italy' => 'Italy', 'Sweden' => 'Sweden', 'Finland' => 'Finland',
            'Malaysia' => 'Malaysia', 'Singapore' => 'Singapore', 'UAE' => 'United Arab Emirates',
        ],

        // 16 program levels
        'level' => [
            'foundation' => 'Foundation', 'pathway' => 'Pathway / Pre-Master\'s',
            'diploma' => 'Diploma', 'advanced_diploma' => 'Advanced Diploma',
            'associate' => 'Associate Degree', 'bachelor' => 'Bachelor\'s Degree',
            'bachelor_honours' => 'Bachelor\'s (Honours)', 'grad_certificate' => 'Graduate Certificate',
            'grad_diploma' => 'Graduate Diploma', 'pg_certificate' => 'Postgraduate Certificate',
            'pg_diploma' => 'Postgraduate Diploma', 'master' => 'Master\'s Degree',
            'mba' => 'MBA', 'integrated_master' => 'Integrated Master\'s',
            'mphil' => 'MPhil', 'phd' => 'PhD / Doctorate',
        ],

        // 7 study areas
        'study_area' => [
            'business' => 'Business & Management', 'engineering' => 'Engineering & Technology',
            'it_computing' => 'IT & Computer Science', 'health' => 'Health & Medicine',
            'arts_design' => 'Arts, Humanities & Design', 'sciences' => 'Natural Sciences',
            'social_law' => 'Social Sciences & Law',
        ],

        // a workable discipline set (fed/extended by ingest)
        'discipline_area' => [
            'data_science' => 'Data Science & Analytics', 'ai_ml' => 'Artificial Intelligence',
            'software' => 'Software Engineering', 'cybersecurity' => 'Cybersecurity',
            'finance' => 'Finance & Accounting', 'marketing' => 'Marketing',
            'civil' => 'Civil Engineering', 'mechanical' => 'Mechanical Engineering',
            'electrical' => 'Electrical Engineering', 'nursing' => 'Nursing',
            'public_health' => 'Public Health', 'pharmacy' => 'Pharmacy',
            'law' => 'Law', 'economics' => 'Economics', 'psychology' => 'Psychology',
            'design' => 'Design', 'architecture' => 'Architecture', 'agriculture' => 'Agriculture',
        ],

        'duration_band' => [
            'lt_1yr' => 'Under 1 year', '1yr' => '1 year', '1_5yr' => '1.5 years',
            '2yr' => '2 years', '3yr' => '3 years', '4yr_plus' => '4+ years',
        ],

        // the three primary study-abroad intakes (+ Winter), each mapped to a month
        'intake' => [
            'spring' => 'Spring (January)', 'summer' => 'Summer (May)',
            'fall' => 'Fall / Autumn (September)', 'winter' => 'Winter (November)',
        ],

        // enquiry/console study level shorthand
        'study_level' => ['ug' => 'Undergraduate', 'pg' => 'Postgraduate', 'phd' => 'PhD', 'diploma' => 'Diploma', 'foundation' => 'Foundation'],

        // student nationalities (VFI's source markets + common)
        'nationality' => [
            'Bangladesh' => 'Bangladesh', 'India' => 'India', 'Nepal' => 'Nepal',
            'Sri Lanka' => 'Sri Lanka', 'Pakistan' => 'Pakistan', 'Nigeria' => 'Nigeria',
            'Ghana' => 'Ghana', 'Kenya' => 'Kenya', 'Vietnam' => 'Vietnam',
            'Philippines' => 'Philippines', 'Indonesia' => 'Indonesia', 'Uzbekistan' => 'Uzbekistan',
        ],

        // requirement tests (facet vocabulary)
        'test' => [
            'ielts' => 'IELTS', 'toefl' => 'TOEFL', 'pte' => 'PTE Academic',
            'duolingo' => 'Duolingo', 'gre' => 'GRE', 'gmat' => 'GMAT',
        ],
    ];
}
