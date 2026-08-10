<?php

namespace App\Enums;

/**
 * The 8-role model (docs/phases/phase-1-...md §Role model).
 * Backed string enum — the portable stand-in for the native PG enum; the same
 * string lands in the DB column on SQLite / MySQL / Postgres alike.
 *
 * Only the machinery + these values land in Phase 1. The tenant-bound
 * partner_* roles get real data in Phase 6; student in Phase 4.
 */
enum Role: string
{
    case Student           = 'student';            // public portal (P4)
    case PartnerOwner      = 'partner_owner';      // tenant-bound (P6)
    case PartnerCounsellor = 'partner_counsellor'; // tenant-bound (P6)
    case StaffCounsellor   = 'staff_counsellor';   // VFI staff
    case StaffPartnerOps   = 'staff_partner_ops';  // reviews agency applications (P6)
    case StaffFinance      = 'staff_finance';      // money writes (P9)
    case ContentEditor     = 'content_editor';     // CMS only (P3)
    case SuperAdmin        = 'superadmin';         // owner: backups, reset, admin users

    /** Tenant-bound roles MUST carry a non-null agency_id (enforced in DB + app). */
    public function isTenantBound(): bool
    {
        return match ($this) {
            self::PartnerOwner, self::PartnerCounsellor => true,
            default => false,
        };
    }

    /**
     * Roles that use the admin panel (/api/admin, Filament) and therefore
     * require mandatory TOTP. Partner/student actors are NOT admin-scope.
     */
    public function usesAdminPanel(): bool
    {
        return match ($this) {
            self::StaffCounsellor, self::StaffPartnerOps, self::StaffFinance,
            self::ContentEditor, self::SuperAdmin => true,
            default => false,
        };
    }

    /** The cookie scope this role authenticates under. */
    public function scope(): string
    {
        return match (true) {
            $this === self::Student => 'student',
            $this->isTenantBound()  => 'partner',
            default                 => 'admin',
        };
    }
}
