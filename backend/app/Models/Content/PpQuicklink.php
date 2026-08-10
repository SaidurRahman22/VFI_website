<?php

namespace App\Models\Content;

class PpQuicklink extends ContentItem
{
    protected $table = 'pp_quicklinks';

    protected $fillable = ['legacy_id', 'position', 'label', 'url'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'label' => 'label', 'url' => 'url',
    ];
}
