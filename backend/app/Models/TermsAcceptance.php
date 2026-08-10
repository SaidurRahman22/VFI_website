<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4A — a stored record that a user accepted a document version, with the
 * when/where. Consent is evidence, so it is written, not merely validated.
 */
class TermsAcceptance extends Model
{
    protected $fillable = ['user_id', 'document', 'version', 'accepted_at', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
