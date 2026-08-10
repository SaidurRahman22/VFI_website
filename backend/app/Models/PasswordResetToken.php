<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * Phase 4A — a single password-reset token. The raw 32-byte CSPRNG token is
 * only ever in the emailed link; at rest we keep the sha256 hash and look up by
 * it. Single-use (`consumed_at`) + supersede (`invalidated_by`) so a used or
 * replaced token is provably dead. Lifecycle enforced in PasswordResetService.
 */
class PasswordResetToken extends Model
{
    protected $fillable = [
        'user_id', 'token_hash', 'requested_for_email', 'expires_at',
        'consumed_at', 'requested_ip', 'consumed_ip', 'invalidated_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
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

    public function isLive(): bool
    {
        return $this->consumed_at === null
            && $this->invalidated_by === null
            && ! $this->isExpired();
    }

    public function markConsumed(?string $ip = null): void
    {
        $this->forceFill([
            'consumed_at' => Date::now(),
            'consumed_ip' => $ip,
            'invalidated_by' => 'reset',
        ])->save();
    }
}
