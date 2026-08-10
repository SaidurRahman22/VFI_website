<?php

namespace App\Models\Partner;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7 — a revocable QR/referral link (tenant-scoped). The slug is opaque and
 * unguessable — no raw agency id ever appears in a URL (docs §6).
 */
class AgencyReferralLink extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'slug', 'created_by_user_id', 'revoked_at', 'max_uses', 'uses_count', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'last_used_at' => 'datetime', 'uses_count' => 'integer', 'max_uses' => 'integer'];
    }

    public function signups(): HasMany
    {
        return $this->hasMany(ReferralSignup::class, 'referral_link_id');
    }

    /** Usable = not revoked and within any usage cap. */
    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->max_uses === null || $this->uses_count < $this->max_uses);
    }
}
