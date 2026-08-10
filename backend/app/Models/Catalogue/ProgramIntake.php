<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 8 — one intake (start term) of a program. */
class ProgramIntake extends Model
{
    protected $fillable = [
        'program_id', 'intake_month', 'intake_year', 'season_label', 'application_deadline_at', 'status',
    ];

    protected function casts(): array
    {
        return ['application_deadline_at' => 'date', 'intake_month' => 'integer', 'intake_year' => 'integer'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
