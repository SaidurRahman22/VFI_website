<?php

namespace App\Models\Content;

use App\Support\UrlGuard;

class PpDoc extends ContentItem
{
    protected $table = 'pp_docs';

    protected $fillable = ['legacy_id', 'position', 'country', 'category', 'title', 'date', 'size', 'url'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'country' => 'country', 'category' => 'category', 'title' => 'title',
        'date' => 'date', 'size' => 'size', 'url' => 'url',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::saving(fn (PpDoc $m) => $m->url = UrlGuard::safe($m->url));
    }
}
