<?php

namespace App\Models\Content;

class NewsItem extends ContentItem
{
    protected $table = 'news';

    protected $fillable = ['legacy_id', 'position', 'title', 'color', 'img_id', 'excerpt'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'title' => 'title', 'color' => 'color', 'img_id' => 'imgId', 'excerpt' => 'excerpt',
    ];
}
