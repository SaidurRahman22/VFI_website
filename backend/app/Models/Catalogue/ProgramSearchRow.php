<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8 — a denormalised flat search row (one per program-intake). Public
 * reference data only; never any PII. Rebuilt-and-swapped by the ingest.
 */
class ProgramSearchRow extends Model
{
    protected $table = 'program_search';

    protected $fillable = [
        'program_id', 'institution_id', 'title', 'university_name', 'country', 'province_state',
        'level', 'study_area', 'discipline_area', 'duration_band', 'tuition_fee_minor',
        'tuition_currency', 'application_deadline_at', 'offer_tat_days', 'intake_month',
        'intake_year', 'season_label', 'search_blob', 'flags', 'is_stale', 'source',
    ];

    protected function casts(): array
    {
        return [
            'application_deadline_at' => 'date',
            'is_stale' => 'boolean',
            'tuition_fee_minor' => 'integer',
            'intake_month' => 'integer',
            'intake_year' => 'integer',
            'offer_tat_days' => 'integer',
        ];
    }
}
