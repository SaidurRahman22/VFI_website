<?php

namespace App\Models\Concerns;

use App\Models\ContentAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an append-only before/after audit row on every create/update/delete
 * of a content model (Phase 3 §7.1). Honours ContentAuditLog::$muted so a bulk
 * import can log one summary row instead of hundreds.
 */
trait LogsContentAudit
{
    public static function bootLogsContentAudit(): void
    {
        static::created(function (Model $m) {
            if (ContentAuditLog::$muted) {
                return;
            }
            ContentAuditLog::record('create', $m->auditEntity(), $m->auditId(), null, $m->getAttributes());
        });

        static::updated(function (Model $m) {
            if (ContentAuditLog::$muted) {
                return;
            }
            ContentAuditLog::record('update', $m->auditEntity(), $m->auditId(), $m->getOriginal(), $m->getAttributes());
        });

        static::deleted(function (Model $m) {
            if (ContentAuditLog::$muted) {
                return;
            }
            ContentAuditLog::record('delete', $m->auditEntity(), $m->auditId(), $m->getOriginal(), null);
        });
    }

    protected function auditEntity(): string
    {
        return $this->getTable();
    }

    protected function auditId(): ?string
    {
        return (string) ($this->getAttribute('legacy_id') ?? $this->getAttribute('key') ?? $this->getKey());
    }
}
