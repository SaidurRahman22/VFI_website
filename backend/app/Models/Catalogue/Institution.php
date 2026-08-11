<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 8 — a university/institution (public reference data). */
class Institution extends Model
{
    protected $fillable = [
        'name', 'tagline', 'country', 'province_state', 'city', 'is_major_city', 'has_own_english_test',
        'offer_tat_band', 'offer_acceptance_band', 'affordability_band', 'tuition_deposit_policy',
        'interview_required', 'vfi_represented', 'logo_key', 'hero_image_key', 'website', 'source', 'external_ref',
        // editorial profile (admin-authored)
        'overview', 'ranking_world', 'ranking_national', 'ranking_note',
        'cost_note', 'living_cost_note', 'accommodation_note',
        'admission_academic', 'admission_english', 'placement_note', 'salary_note',
        'scholarships_json', 'faqs_json', 'gallery_json', 'recruiters_json',
    ];

    protected function casts(): array
    {
        return [
            'is_major_city' => 'boolean', 'has_own_english_test' => 'boolean',
            'interview_required' => 'boolean', 'vfi_represented' => 'boolean',
            'scholarships_json' => 'array', 'faqs_json' => 'array',
            'gallery_json' => 'array', 'recruiters_json' => 'array',
        ];
    }

    /** Public URL for an uploaded asset on the `public` disk (served at /storage). */
    private function publicUrl(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        // absolute URLs (e.g. an externally-hosted logo) pass through untouched
        return preg_match('#^https?://#', $key) ? $key : '/storage/'.ltrim($key, '/');
    }

    public function logoUrl(): ?string
    {
        return $this->publicUrl($this->logo_key);
    }

    public function heroUrl(): ?string
    {
        return $this->publicUrl($this->hero_image_key);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
