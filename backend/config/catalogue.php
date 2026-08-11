<?php

return [

    /*
    | Phase 8 — program catalogue ingest.
    |
    | REAL program-level data is freely available only for the US (College
    | Scorecard) and Germany (DAAD). VFI's other destinations (UK, Canada,
    | Australia, Ireland, NZ) have no free feed, so they are filled with a
    | clearly-flagged `seed` placeholder until a licensed feed is supplied
    | (see Developer_requier.md §Priority 4). Swapping in a real feed is an
    | ingest-source change, no schema/query change.
    */

    // College Scorecard (US). DEMO_KEY works but is rate-limited; a free key from
    // https://api.data.gov/signup lifts the limit (Developer_requier.md §4C).
    'scorecard' => [
        'key' => env('CATALOGUE_SCORECARD_KEY', 'DEMO_KEY'),
        'base' => 'https://api.data.gov/ed/collegescorecard/v1/schools',
        'max_institutions' => (int) env('CATALOGUE_SCORECARD_MAX', 120),
        // tiny pages so each request finishes under the DEMO_KEY throttle; raise
        // once a real CATALOGUE_SCORECARD_KEY is set (see Developer_requier.md §4C).
        'per_page' => (int) env('CATALOGUE_SCORECARD_PER_PAGE', 10),
    ],

    // DAAD International Programmes (Germany, English-taught degrees) — open API.
    'daad' => [
        'base' => 'https://www2.daad.de/deutschland/studienangebote/international-programmes/api/solr/en/search.json',
        'max' => (int) env('CATALOGUE_DAAD_MAX', 600),
    ],

    // Synthetic seed size for the no-free-feed countries.
    'seed' => [
        'universities_per_country' => (int) env('CATALOGUE_SEED_UNIS', 6),
        'programs_per_university' => (int) env('CATALOGUE_SEED_PROGRAMS', 8),
        'base_year' => 2026,
    ],

    // Per-session search rate limit (infra limiter; quota is the commercial one).
    'search_rate_per_minute' => (int) env('CATALOGUE_SEARCH_RATE', 40),
];
