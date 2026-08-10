<?php

namespace App\Enums;

/**
 * Phase 5 — the scan-gate state of a stored blob (docs §Scan-gate). A file is
 * written to private storage as `pending` and becomes readable ONLY after it
 * is marked `clean`. `infected` is quarantined and never served.
 */
enum ScanStatus: string
{
    case Pending  = 'pending';
    case Clean    = 'clean';
    case Infected = 'infected';

    /** Only a clean file may ever have a download URL minted. */
    public function isReadable(): bool
    {
        return $this === self::Clean;
    }
}
