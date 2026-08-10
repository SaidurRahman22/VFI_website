<?php

namespace App\Models\Partner;

use App\Enums\ApplicationReviewStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — a partner registration held for staff review. NOT a live tenant:
 * approval mints a partner_agencies row and links it here via agency_id.
 * Deliberately not agency-scoped (staff read all applications).
 */
class PartnerApplication extends Model
{
    protected $fillable = [
        'agency_name', 'country', 'city', 'contact_person', 'work_email',
        'phone_cc', 'phone_national', 'user_id', 'terms_accepted_version',
        'authorised_signatory_attested', 'email_change_count', 'submitted_at',
        'submitted_ip', 'review_status', 'reviewed_by_user_id', 'reviewed_at',
        'review_notes', 'agency_id',
    ];

    protected function casts(): array
    {
        return [
            'review_status' => ApplicationReviewStatus::class,
            'authorised_signatory_attested' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(PartnerAgency::class, 'agency_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
