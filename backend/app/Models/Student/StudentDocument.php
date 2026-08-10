<?php

namespace App\Models\Student;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'student_id', 'document_type_id', 'status', 'file_id',
        'uploaded_at', 'verified_by', 'verified_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DocumentFile::class, 'file_id');
    }
}
