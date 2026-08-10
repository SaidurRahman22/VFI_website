<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentApplication extends Model
{
    protected $fillable = [
        'student_id', 'university', 'place', 'course', 'intake',
        'sent_on', 'status', 'pct', 'stage', 'note', 'position',
    ];

    protected function casts(): array
    {
        return ['sent_on' => 'date:Y-m-d', 'pct' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
