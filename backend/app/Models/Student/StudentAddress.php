<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAddress extends Model
{
    protected $fillable = ['student_id', 'line1', 'line2', 'city', 'district', 'postcode', 'country'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
