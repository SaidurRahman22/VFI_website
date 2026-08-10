<?php

namespace App\Models\Catalogue;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Student\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8 — a program saved to a student's shortlist. Tenant-scoped: an agency
 * only ever sees its own shortlists (BelongsToAgency + Postgres RLS).
 */
class ProgramShortlist extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'student_id', 'program_id', 'note', 'created_by_user_id'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
