<?php

return [

    /*
    | Cloudflare Turnstile (bot protection) for the public contact form.
    | Deferred until a site/secret key exists — off by default. When you add
    | keys, set CONTACT_TURNSTILE_ENABLED=true. Until then, rate-limiting +
    | strict validation are the protection (docs §7.2).
    */
    'turnstile' => [
        'enabled' => env('CONTACT_TURNSTILE_ENABLED', false),
        'secret' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    // Allowed destination values (server-side allow-list mirrors the form select).
    'destinations' => [
        'United States', 'United Kingdom', 'Canada', 'Australia',
        'Ireland', 'New Zealand', 'Europe', 'Asia',
    ],
];
