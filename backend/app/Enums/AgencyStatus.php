<?php

namespace App\Enums;

/**
 * Phase 6 — lifecycle of a partner agency (THE tenant). Sign-in is refused for
 * every status except Approved (docs §6 status gate). Suspend/close additionally
 * revoke live member sessions.
 */
enum AgencyStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /** Only an approved agency may sign in / hold a live tenant session. */
    public function canOperate(): bool
    {
        return $this === self::Approved;
    }
}
