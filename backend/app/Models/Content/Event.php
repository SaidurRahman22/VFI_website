<?php

namespace App\Models\Content;

class Event extends ContentItem
{
    protected $table = 'events';

    protected $fillable = ['legacy_id', 'position', 'title', 'date', 'time', 'type', 'city', 'description', 'color', 'img_id'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'title' => 'title', 'date' => 'date', 'time' => 'time',
        'type' => 'type', 'city' => 'city', 'description' => 'desc', 'color' => 'color', 'img_id' => 'imgId',
    ];
}
