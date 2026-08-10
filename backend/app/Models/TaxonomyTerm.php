<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lookup terms (blog categories, event types, destination countries, …) AND —
 * from Phase 8 — the served program-search vocabularies (level, study_area,
 * country, intake, …). One table = the single source that kills the divergent
 * hardcoded option lists. `kind` is the vocabulary, `value` the machine code.
 */
class TaxonomyTerm extends Model
{
    protected $fillable = ['kind', 'value', 'label', 'position', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
