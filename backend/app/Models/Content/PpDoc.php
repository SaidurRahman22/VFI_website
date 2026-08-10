<?php

namespace App\Models\Content;

class PpDoc extends ContentItem
{
    protected $table = 'pp_docs';

    protected $fillable = ['legacy_id', 'position', 'country', 'category', 'title', 'date', 'size', 'url'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'country' => 'country', 'category' => 'category', 'title' => 'title',
        'date' => 'date', 'size' => 'size', 'url' => 'url',
    ];
}
