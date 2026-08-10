<?php

namespace App\Models\Content;

use App\Models\Concerns\LogsContentAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base for the 10 content collections (Phase 2B). Shared: soft-delete, explicit
 * `position` ordering with "new item to front", toBundle() key-mapping, and an
 * append-only audit row on every write (Phase 3).
 */
abstract class ContentItem extends Model
{
    use LogsContentAudit, SoftDeletes;

    /** column => frontend key. Always includes legacy_id => id. */
    protected array $bundleMap = [];

    protected static function booted(): void
    {
        static::creating(function (ContentItem $m) {
            // Auto-mint an immutable legacy_id so admins never type one
            // (blogs: this is the public URL key — never edit it later).
            if (blank($m->getAttribute('legacy_id'))) {
                $m->legacy_id = substr(class_basename($m), 0, 1).'_'.\Illuminate\Support\Str::random(10);
            }
            // New item defaults to the front (lowest position), mirroring
            // VFI.put()'s unshift — unless position was set explicitly
            // (the importer sets it from array index, so it is respected).
            if ($m->getAttribute('position') === null) {
                $min = static::query()->min('position');
                $m->position = ($min ?? 1) - 1;
            }
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** The exact frontend object shape for the content bundle. */
    public function toBundle(): array
    {
        $out = [];
        foreach ($this->bundleMap as $col => $key) {
            $v = $this->getAttribute($col);
            if ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d');
            }
            $out[$key] = $v;
        }

        return $out;
    }

    /** Reverse of toBundle: a frontend item → DB column attributes (for import). */
    public function mapFromBundle(array $item): array
    {
        $attrs = [];
        foreach ($this->bundleMap as $col => $key) {
            if (array_key_exists($key, $item)) {
                $attrs[$col] = $item[$key];
            }
        }

        return $attrs;   // includes legacy_id (from item['id'])
    }
}
