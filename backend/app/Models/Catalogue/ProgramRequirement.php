<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 8 — an entry requirement (test/GPA). waiver_available = the NEGATIVE flag. */
class ProgramRequirement extends Model
{
    protected $fillable = [
        'program_id', 'test', 'min_overall', 'min_subscore_json',
        'is_required', 'waiver_available', 'academic_min_gpa', 'maths_required',
    ];

    protected function casts(): array
    {
        return [
            'min_subscore_json' => 'array', 'is_required' => 'boolean',
            'waiver_available' => 'boolean', 'maths_required' => 'boolean',
            'min_overall' => 'decimal:2', 'academic_min_gpa' => 'decimal:2',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
