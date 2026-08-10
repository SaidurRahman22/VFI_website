<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 8 — a university/institution (public reference data). */
class Institution extends Model
{
    protected $fillable = [
        'name', 'country', 'province_state', 'city', 'is_major_city', 'has_own_english_test',
        'offer_tat_band', 'offer_acceptance_band', 'affordability_band', 'tuition_deposit_policy',
        'interview_required', 'vfi_represented', 'logo_key', 'source', 'external_ref',
    ];

    protected function casts(): array
    {
        return [
            'is_major_city' => 'boolean', 'has_own_english_test' => 'boolean',
            'interview_required' => 'boolean', 'vfi_represented' => 'boolean',
        ];
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
