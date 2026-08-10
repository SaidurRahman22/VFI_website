<?php

namespace App\Models\Content;

class Photo extends ContentItem
{
    protected $table = 'photos';

    protected $fillable = ['legacy_id', 'position', 'img_id', 'caption', 'alt'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'img_id' => 'imgId', 'caption' => 'caption', 'alt' => 'alt',
    ];
}
