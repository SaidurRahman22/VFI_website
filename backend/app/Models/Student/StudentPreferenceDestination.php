<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPreferenceDestination extends Model
{
    protected $fillable = ['student_id', 'destination', 'position'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
