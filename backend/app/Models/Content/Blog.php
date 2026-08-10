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

    protected static function booted(): void
    {
        parent::booted();
        // Anti-stored-XSS contract: blog body is PLAIN TEXT end-to-end. Strip any
        // HTML on save; the "## / - / >" markers are plain text and survive.
        static::saving(function (Blog $b) {
            if ($b->body !== null) {
                $b->body = strip_tags((string) $b->body);
            }
        });
    }
}
