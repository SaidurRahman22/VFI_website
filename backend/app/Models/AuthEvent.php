<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Append-only audit record (docs §1.4). Never updated or deleted — the model
 * blocks both at the app layer; Postgres additionally REVOKEs UPDATE/DELETE.
 * Use AuthEvent::record(...) so callers can't accidentally mass-assign context
 * that hasn't been scrubbed.
 */
class AuthEvent extends Model
{
    public $timestamps = false;   // append-only: created_at only

    protected $fillable = ['user_id', 'email', 'event', 'ip', 'user_agent', 'context', 'created_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuthEvent $e) {
            $e->created_at ??= Date::now();
        });
        static::updating(fn () => throw new \RuntimeException('auth_events is append-only.'));
        static::deleting(fn () => throw new \RuntimeException('auth_events is append-only.'));
    }

    public static function record(string $event, array $attrs = []): self
    {
        return static::create(array_merge(['event' => $event], $attrs));
    }
}
