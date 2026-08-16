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

    /**
     * Every application this agency has filed, ignoring the tenant scope.
     * Admin-facing aggregate only: staff hold no tenant, so the scoped relation
     * would fail-closed to zero. Safe here because `applications` is guarded by
     * the Eloquent scope alone (no RLS policy) — do NOT copy this pattern for
     * RLS-protected tables, where the database would still hide the rows.
     */
    public function applicationsAll(): HasMany
    {
        return $this->hasMany(Application::class, 'agency_id')->withoutGlobalScopes();
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
