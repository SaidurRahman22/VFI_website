<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Mandatory admin TOTP
    |--------------------------------------------------------------------------
    | Phase 1 requires two-factor for every admin/staff sign-in. Keep this TRUE
    | in production. It may be set false in LOCAL DEV only (ADMIN_REQUIRE_TOTP=false)
    | for password-only convenience — never on a public deployment.
    */

    'admin_require_totp' => env('ADMIN_REQUIRE_TOTP', true),

    /*
    |--------------------------------------------------------------------------
    | Breach-list password check (Phase 4 §5.4)
    |--------------------------------------------------------------------------
    | Reject known-breached passwords on register + reset via the HIBP
    | k-anonymity API. Fails open (never blocks on a network error). Disabled in
    | the test env to avoid an outbound call.
    */
    'breach_check' => env('AUTH_BREACH_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Demo OTP  (TEMPORARY — clear before onboarding real users)
    |--------------------------------------------------------------------------
    | While mail is on the `log` driver no OTP ever reaches an inbox, so signup
    | and verification cannot be walked through. Setting AUTH_DEMO_OTP makes the
    | verifier ALSO accept that one fixed code, on top of the real one.
    |
    | Empty = disabled, which is the default and the correct production state.
    | Nothing else is relaxed: the flow id, expiry, attempt cap and single-use
    | consumption still apply, and every use writes an `otp_demo_bypass` auth
    | event plus a warning log, so the window it was open for stays auditable.
    */
    'demo_otp' => env('AUTH_DEMO_OTP', ''),

    /*
    |--------------------------------------------------------------------------
    | Cross-tenant read roles  (Phase 9A slice 5)
    |--------------------------------------------------------------------------
    | Which staff roles may open a student record belonging to a partner agency.
    | Confirmed with the client: superadmin + staff_counsellor.
    |
    | Comma-separated role values. Leave empty for the confirmed default — an
    | empty or unparseable list falls back to that pair rather than opening the
    | door wider, so a typo can only ever narrow access.
    */
    'cross_tenant_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AUTH_CROSS_TENANT_ROLES', ''))
    ))),

];
