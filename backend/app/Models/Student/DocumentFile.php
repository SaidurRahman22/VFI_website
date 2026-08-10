<?php

namespace App\Models\Student;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentFile extends Model
{
    protected $fillable = [
        'student_id', 'document_type_id', 'storage_key', 'original_name',
        'mime', 'size', 'sha256', 'scan_status',
    ];

    protected function casts(): array
    {
        return ['scan_status' => ScanStatus::class, 'size' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function isReadable(): bool
    {
        return $this->scan_status->isReadable();
    }
}
