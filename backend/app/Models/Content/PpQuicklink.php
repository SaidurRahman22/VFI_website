<?php

namespace App\Models\Content;

use App\Support\UrlGuard;

class PpQuicklink extends ContentItem
{
    protected $table = 'pp_quicklinks';

    protected $fillable = ['legacy_id', 'position', 'label', 'url'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'label' => 'label', 'url' => 'url',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::saving(fn (PpQuicklink $m) => $m->url = UrlGuard::safe($m->url));
    }
}
