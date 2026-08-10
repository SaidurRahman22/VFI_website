<?php

namespace App\Models\Partner;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Student\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 — a signup attributed to a referral link. `converted_at` is set only
 * once the student verifies their email — attribution counts from that moment
 * (docs §6, anti-commission-farming).
 */
class ReferralSignup extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'referral_link_id', 'student_id', 'ref_code_seen',
        'landed_at', 'converted_at', 'channel',
    ];

    protected function casts(): array
    {
        return ['landed_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AgencyReferralLink::class, 'referral_link_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
