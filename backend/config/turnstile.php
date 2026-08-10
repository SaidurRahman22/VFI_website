<?php

return [

    /*
    | Cloudflare Turnstile for the unauthenticated auth writes (register, forgot,
    | OTP resend) — docs §5.3. Deferred until a site/secret key exists: OFF by
    | default. Set TURNSTILE_ENABLED=true once keys are configured. Until then,
    | server-side rate limiting + strict validation are the protection.
    */
    'enabled' => env('TURNSTILE_ENABLED', false),
    'secret' => env('TURNSTILE_SECRET_KEY'),
    'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
];
