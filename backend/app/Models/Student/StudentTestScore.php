<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTestScore extends Model
{
    protected $fillable = ['student_id', 'test', 'score_raw', 'score_numeric', 'taken_on', 'position'];

    protected function casts(): array
    {
        return ['taken_on' => 'date:Y-m-d', 'score_numeric' => 'decimal:2'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
