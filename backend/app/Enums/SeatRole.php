<?php

namespace App\Enums;

/**
 * Phase 6 — a member's seat within an agency. The wizard mints exactly one
 * `owner`; the table supports N seats (invite UI is later product surface).
 */
enum SeatRole: string
{
    case Owner = 'owner';
    case Counsellor = 'counsellor';
    case FinanceViewer = 'finance_viewer';

    /** The partner-scoped app role this seat authenticates under. */
    public function role(): Role
    {
        return match ($this) {
            self::Owner => Role::PartnerOwner,
            default => Role::PartnerCounsellor,
        };
    }
}
