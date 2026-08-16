<?php

return [

    /*
    | Phase 5 — student document storage + scan-gate.
    |
    | R2/S3 is deferred, so blobs live on the private local `documents` disk.
    | ClamAV is deferred, so the default scanner is `builtin` (magic-byte sniff +
    | EICAR signature detection) — enough to enforce a real scan-gate and quarantine
    | the standard test virus. Set DOCUMENTS_SCANNER=clamav + a reachable clamd once
    | the sidecar exists; nothing else in the app changes.
    */

    'disk' => env('DOCUMENTS_DISK', 'documents'),

    // Multi-MB phone scans are expected from Bangladeshi mobile links.
    'max_bytes' => (int) env('DOCUMENTS_MAX_BYTES', 15 * 1024 * 1024),   // 15 MB

    // Server-side allow-list (the student <input> has no `accept` attr).
    'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],

    'scanner' => env('DOCUMENTS_SCANNER', 'builtin'),   // builtin | clamav

    'clamav' => [
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

    // Single-use presigned-GET lifetime (docs §Signed URLs: 60–300s).
    'download_ttl' => (int) env('DOCUMENTS_DOWNLOAD_TTL', 120),

    // Phase 9B — default retention clock. A file with no explicit clock is kept
    // this many years from upload, then its bytes are destroyed (the row, the
    // checklist and every audit line survive as proof of the deletion).
    'retention_years' => (int) env('DOCUMENTS_RETENTION_YEARS', 7),
];
