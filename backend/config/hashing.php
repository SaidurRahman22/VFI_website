<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    | Phase 1 security requirement: passwords are stored with argon2id, not
    | bcrypt. Params below match docs/phases/phase-1-...md §1.1 and the
    | security-and-compliance doc: 64 MiB memory, time cost 3, 1 thread.
    */

    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    'argon' => [
        'memory' => 65536,   // 64 MiB (KiB units)
        'threads' => 1,
        'time' => 3,
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
