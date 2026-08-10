<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Request;

/**
 * Append-only audit record for content mutations (Phase 3 §7.1).
 * Blocks update/delete at the app layer; Postgres also REVOKEs them.
 */
class ContentAuditLog extends Model
{
    public $timestamps = false;   // created_at only

    /** When true, per-row audit hooks are silenced (bulk import writes one summary row). */
    public static bool $muted = false;

    protected $table = 'content_audit_log';

    protected $fillable = ['actor_user_id', 'action', 'entity', 'entity_id', 'before', 'after', 'ip', 'created_at'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ContentAuditLog $e) => $e->created_at ??= Date::now());
        static::updating(fn () => throw new \RuntimeException('content_audit_log is append-only.'));
        static::deleting(fn () => throw new \RuntimeException('content_audit_log is append-only.'));
    }

    /** Record one mutation. Actor + ip resolved from the current request context. */
    public static function record(string $action, string $entity, ?string $entityId, ?array $before, ?array $after): self
    {
        return static::create([
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
        ]);
    }
}
