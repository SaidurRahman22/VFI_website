<?php

namespace App\Models\Content;

class Blog extends ContentItem
{
    protected $table = 'blogs';

    protected $fillable = ['legacy_id', 'position', 'title', 'category', 'date', 'excerpt', 'color', 'img_id', 'author', 'read_time', 'body'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'title' => 'title', 'category' => 'category', 'date' => 'date',
        'excerpt' => 'excerpt', 'color' => 'color', 'img_id' => 'imgId',
        'author' => 'author', 'read_time' => 'readTime', 'body' => 'body',
    ];
}
