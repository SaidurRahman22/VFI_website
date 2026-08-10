<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * Phase 4A — one issued email OTP, keyed on an opaque `flow_id`. The 6-digit
 * code is stored only as an argon2id hash (`code_hash`); this row is the whole
 * server-side state for the verify flow, so the email never needs to ride in a
 * URL. Enforcement (TTL, attempts, single-use, supersede) lives in OtpService.
 */
class EmailVerificationCode extends Model
{
    protected $fillable = [
        'flow_id', 'user_id', 'email', 'code_hash', 'purpose',
        'attempts_used', 'max_attempts', 'expires_at', 'last_sent_at',
        'consumed_at', 'request_ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts_used >= $this->max_attempts;
    }

    /** Usable = not consumed, not expired, attempts remaining. */
    public function isLive(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired() && ! $this->attemptsExhausted();
    }

    public function markConsumed(): void
    {
        $this->forceFill(['consumed_at' => Date::now()])->save();
    }
}
