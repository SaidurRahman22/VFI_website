<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentQualification extends Model
{
    protected $fillable = ['student_id', 'qualification', 'institution', 'year', 'grade', 'position'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
