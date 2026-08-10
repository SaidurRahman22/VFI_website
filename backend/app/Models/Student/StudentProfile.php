<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    protected $fillable = ['student_id', 'first', 'middle', 'last', 'dob', 'nationality', 'cc', 'phone', 'email'];

    protected function casts(): array
    {
        return ['dob' => 'date:Y-m-d'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
