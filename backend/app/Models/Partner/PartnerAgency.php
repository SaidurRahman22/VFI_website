<?php

namespace App\Models\Partner;

use App\Enums\AgencyStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phase 6 — a partner agency (THE tenant). Staff-managed, so NOT agency-scoped
 * itself; a partner reaches its own agency only via its membership.
 */
class PartnerAgency extends Model
{
    protected $fillable = [
        'legal_name', 'country', 'city', 'status', 'tier_id', 'seat_limit',
        'wallet_id', 'approved_by_user_id', 'approved_at', 'rejected_reason',
    ];

    protected function casts(): array
    {
        return ['status' => AgencyStatus::class, 'approved_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(PartnerAgencyMember::class, 'agency_id');
    }

    public function application(): HasOne
    {
        return $this->hasOne(PartnerApplication::class, 'agency_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
