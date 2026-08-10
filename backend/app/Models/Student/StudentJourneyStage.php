<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentJourneyStage extends Model
{
    protected $fillable = ['student_id', 'name', 'state', 'when_label', 'position'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
