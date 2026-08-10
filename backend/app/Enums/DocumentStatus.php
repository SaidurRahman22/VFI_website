<?php

namespace App\Enums;

/**
 * Phase 5 — a student document's checklist state. `rejected` is NEW this phase
 * (docs §2.3): today an unreadable passport can only sit as `uploaded` forever.
 * `verified` is set only by staff (Phase 9) — a student upload can reach
 * `uploaded`, never `verified`.
 */
enum DocumentStatus: string
{
    case Missing  = 'missing';
    case Uploaded = 'uploaded';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /** Counts toward the completeness meter (docs §1.7). */
    public function isPresent(): bool
    {
        return $this === self::Uploaded || $this === self::Verified;
    }

    /** A verified document is frozen — the student cannot remove/replace it. */
    public function isLocked(): bool
    {
        return $this === self::Verified;
    }
}
