<?php

namespace App\Enums;

/**
 * Phase 6 — staff review state of a partner application. Approval mints the
 * tenant; a rejected/more-info application never touches partner_agencies.
 */
enum ApplicationReviewStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case MoreInfo = 'more_info';
}
