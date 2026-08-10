<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base for the 10 content collections (Phase 2B). Shared: soft-delete, explicit
 * `position` ordering with "new item to front", and toBundle() which maps DB
 * columns to the exact frontend keys the render.js appliers expect.
 */
abstract class ContentItem extends Model
{
    use SoftDeletes;

    /** column => frontend key. Always includes legacy_id => id. */
    protected array $bundleMap = [];

    protected static function booted(): void
    {
        static::creating(function (ContentItem $m) {
            // New item defaults to the front (lowest position), mirroring
            // VFI.put()'s unshift — only when position was not set explicitly
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
