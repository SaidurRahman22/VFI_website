<?php

return [

    /*
    | Phase 6 — partner registration. The country list mirrors the wizard's
    | server-side allow-list (steps 1–2 are client-only today and trivially
    | bypassed, so the server re-validates). Keep in sync with vfi-partner-login.html.
    */
    'countries' => [
        'Bangladesh', 'India', 'Nepal', 'Sri Lanka', 'Pakistan', 'Nigeria',
        'Ghana', 'Kenya', 'Vietnam', 'Philippines', 'Indonesia', 'Uzbekistan', 'Other',
    ],

    'terms_version' => '2026-08',

    // Max destination-address changes per pending registration (docs §4).
    'max_email_changes' => 2,
];
