<?php

namespace App\Models\Partner;

use App\Enums\ScanStatus;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 — an enquiry document blob. Reuses the Phase 5 scan-gate: written to
 * the private disk as `pending`, readable only once `scan_status = clean`.
 */
class ProgramRequestDocument extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'program_request_id', 'agency_id', 'storage_key', 'original_filename',
        'content_type', 'size_bytes', 'sha256', 'scan_status', 'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return ['scan_status' => ScanStatus::class, 'size_bytes' => 'integer', 'uploaded_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProgramRequest::class, 'program_request_id');
    }

    public function isReadable(): bool
    {
        return $this->scan_status->isReadable();
    }
}
