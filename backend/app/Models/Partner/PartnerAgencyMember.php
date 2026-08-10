<?php

namespace App\Models\Partner;

use App\Enums\MemberStatus;
use App\Enums\SeatRole;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — a seat within an agency. This is the one tenant-scoped table in P6:
 * BelongsToAgency (keyed on agency_id) is the primary isolation net, Postgres
 * RLS the second. Reads are default-denied when no tenant is in session context.
 */
class PartnerAgencyMember extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'user_id', 'seat_role', 'contact_person_name', 'work_email',
        'phone_cc', 'phone_national', 'invited_by_user_id', 'accepted_at', 'status',
    ];

    protected function casts(): array
    {
        return ['seat_role' => SeatRole::class, 'status' => MemberStatus::class, 'accepted_at' => 'datetime'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(PartnerAgency::class, 'agency_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
