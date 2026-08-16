<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9B — the register of GDPR export / erasure requests.
 *
 * The row is created BEFORE the work runs and updated with the outcome, so a
 * request that failed or was blocked by a legal hold is still on the record.
 * A register that only lists successes is not a register.
 */
class DataSubjectRequest extends Model
{
    public const TYPE_EXPORT = 'export';

    public const TYPE_ERASURE = 'erasure';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type', 'subject_type', 'subject_id', 'subject_email', 'status',
        'reason', 'outcome', 'requested_by_user_id', 'artifact_path', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isDownloadable(): bool
    {
        return $this->type === self::TYPE_EXPORT
            && $this->status === self::STATUS_COMPLETED
            && filled($this->artifact_path);
    }
}
