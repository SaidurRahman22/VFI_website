<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 8 — a program offered by an institution (public reference data). */
class Program extends Model
{
    protected $fillable = [
        'institution_id', 'title', 'level', 'study_area', 'discipline_area', 'duration_band',
        'esl_elp_available', 'tuition_fee_minor', 'tuition_currency', 'tuition_basis', 'application_fee_minor',
        'application_fee_currency', 'is_stem', 'has_coop_internship', 'scholarship_available',
        'application_fee_waiver', 'moi_acceptable', 'job_demand_band', 'is_open', 'source', 'external_ref',
    ];

    protected function casts(): array
    {
        return [
            'esl_elp_available' => 'boolean', 'is_stem' => 'boolean', 'has_coop_internship' => 'boolean',
            'scholarship_available' => 'boolean', 'application_fee_waiver' => 'boolean',
            'moi_acceptable' => 'boolean', 'is_open' => 'boolean',
            'tuition_fee_minor' => 'integer', 'application_fee_minor' => 'integer',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function intakes(): HasMany
    {
        return $this->hasMany(ProgramIntake::class)->orderBy('intake_year')->orderBy('intake_month');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ProgramRequirement::class);
    }

    public function nationalityRules(): HasMany
    {
        return $this->hasMany(ProgramNationalityRule::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ProgramLabel::class, 'program_label_map');
    }
}
