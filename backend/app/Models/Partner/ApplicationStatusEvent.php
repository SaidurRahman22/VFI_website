<?php

namespace App\Models\Partner;

use App\Enums\ActorType;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * Phase 7 — append-only pipeline history (docs §4). Tenant-scoped; never updated
 * or deleted (blocked at the app layer; Postgres additionally has no UPDATE/DELETE
 * intent under RLS FORCE).
 */
class ApplicationStatusEvent extends Model
{
    use BelongsToAgency;

    public $timestamps = false;

    protected $fillable = [
        'application_id', 'agency_id', 'from_status', 'to_status',
        'occurred_at', 'actor_type', 'actor_id', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime', 'actor_type' => ActorType::class];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->created_at ??= Date::now());
        static::updating(fn () => throw new \RuntimeException('application_status_events is append-only.'));
        static::deleting(fn () => throw new \RuntimeException('application_status_events is append-only.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
