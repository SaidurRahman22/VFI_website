<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTimelineEvent extends Model
{
    protected $fillable = ['student_id', 'occurred_on', 'tone', 'icon', 'title', 'body', 'position'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
