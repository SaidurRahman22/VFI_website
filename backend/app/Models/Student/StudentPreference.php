<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPreference extends Model
{
    protected $fillable = ['student_id', 'intake', 'budget', 'field'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
