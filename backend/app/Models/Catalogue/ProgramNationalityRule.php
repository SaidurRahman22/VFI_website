<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 8 — per-nationality eligibility / fee override for a program. */
class ProgramNationalityRule extends Model
{
    protected $fillable = ['program_id', 'nationality', 'eligible', 'notes', 'fee_override_minor'];

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'fee_override_minor' => 'integer'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
