<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9A — one row per deliberate cross-tenant read of a person's record.
 * Append-only: no timestamps pair, no update path. Written by StaffAccessService
 * BEFORE the record is shown, so a read that fails to log never happens.
 */
class StaffAccessLog extends Model
{
    protected $table = 'staff_access_log';

    public $timestamps = false;

    protected $fillable = [
        'actor_user_id', 'actor_email', 'subject_type', 'subject_id',
        'subject_agency_id', 'reason', 'ip', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
