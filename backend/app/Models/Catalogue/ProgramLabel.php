<?php

namespace App\Models\Catalogue;

use Illuminate\Database\Eloquent\Model;

/** Phase 8 — a curated/derived program tag (Scholarship, Co-op, STEM, …). */
class ProgramLabel extends Model
{
    protected $fillable = ['code', 'label'];
}
