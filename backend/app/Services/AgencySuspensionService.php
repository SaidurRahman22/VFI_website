<?php

namespace App\Services;

use App\Enums\AgencyStatus;
use App\Models\ContentAuditLog;
use App\Models\Partner\PartnerAgency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9A slice 3 — suspend / close / reinstate a partner agency.
 *
 * PartnerReview::setAgencyStatus() already flipped the status and revoked member
 * sessions, but nothing ever called it and it recorded no reason and no audit —
 * cutting off a business's access is exactly the action that must be
 * attributable. This wraps it with the missing controls.
 *
 * What suspension actually does:
 *   - status flips, so the sign-in gate refuses the agency (AgencyStatus::canOperate);
 *   - every member's session row is deleted and their remember_token rotated,
 *     so anyone already signed in is cut off on their NEXT request rather than
 *     when their session happens to expire;
 *   - tenant-scoped writes stop because sign-in is the only way to obtain a
 *     tenant context.
 *
 * Reinstating is a separate, equally audited action.
 */
class AgencySuspensionService
{
    public function __construct(private readonly PartnerReview $review) {}

    public function suspend(PartnerAgency $agency, User $staff, string $reason): PartnerAgency
    {
        return $this->apply($agency, AgencyStatus::Suspended, $staff, $reason);
    }

    public function close(PartnerAgency $agency, User $staff, string $reason): PartnerAgency
    {
        return $this->apply($agency, AgencyStatus::Closed, $staff, $reason);
    }

    /** Put a suspended agency back to work. Closed is deliberately final. */
    public function reinstate(PartnerAgency $agency, User $staff, string $reason): PartnerAgency
    {
        if ($agency->status === AgencyStatus::Closed) {
            throw new RuntimeException('A closed agency cannot be reinstated — re-register it instead.');
        }

        return $this->apply($agency, AgencyStatus::Approved, $staff, $reason);
    }

    private function apply(PartnerAgency $agency, AgencyStatus $to, User $staff, string $reason): PartnerAgency
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reason is required — this decision cuts off, or restores, a partner’s access.');
        }
        $from = $agency->status;
        if ($from === $to) {
            throw new RuntimeException('This agency is already '.$to->value.'.');
        }

        return DB::transaction(function () use ($agency, $from, $to, $staff, $reason) {
            // reuse the existing primitive: status flip + session revocation
            $this->review->setAgencyStatus($agency, $to);

            ContentAuditLog::record(
                'agency_status',
                'partner_agency',
                (string) $agency->id,
                ['status' => $from->value],
                ['status' => $to->value, 'reason' => mb_substr($reason, 0, 1000), 'actor_user_id' => $staff->id],
            );

            return $agency->refresh();
        });
    }
}
