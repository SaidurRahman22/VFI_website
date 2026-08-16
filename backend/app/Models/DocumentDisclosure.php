<?php

namespace App\Models;

use App\Models\Student\DocumentFile;
use App\Models\Student\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9B — a record that a document left VFI.
 *
 * Deliberately separate from document_access_log: that says who VIEWED a file
 * inside the system, this says who RECEIVED it outside. Access is not
 * disclosure, and only the second one is what a data subject is entitled to be
 * told about.
 *
 * Append-only: created_at only, no update path.
 */
class DocumentDisclosure extends Model
{
    public $timestamps = false;

    public const RECIPIENT_TYPES = ['university', 'lender', 'government', 'other'];

    public const LAWFUL_BASES = ['consent', 'contract', 'legal_obligation', 'legitimate_interest'];

    protected $fillable = [
        'document_file_id', 'student_id', 'recipient_name', 'recipient_type',
        'lawful_basis', 'note', 'disclosed_by_user_id', 'disclosed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['disclosed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DocumentFile::class, 'document_file_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function disclosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disclosed_by_user_id');
    }
}
