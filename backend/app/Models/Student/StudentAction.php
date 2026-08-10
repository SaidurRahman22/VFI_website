<?php

namespace App\Models\Student;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAction extends Model
{
    protected $fillable = ['student_id', 'icon', 'title', 'body', 'due_at', 'done', 'position'];

    protected function casts(): array
    {
        return ['due_at' => 'date:Y-m-d', 'done' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** Server-computed (docs §4.3): overdue = has a due date, not done, past. */
    public function isOverdue(): bool
    {
        return $this->due_at !== null && ! $this->done && $this->due_at->isPast();
    }
}
