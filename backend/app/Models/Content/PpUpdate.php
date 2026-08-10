<?php

namespace App\Models\Content;

class PpUpdate extends ContentItem
{
    protected $table = 'pp_updates';

    protected $fillable = ['legacy_id', 'position', 'flag', 'title', 'sub', 'date'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'flag' => 'flag', 'title' => 'title', 'sub' => 'sub', 'date' => 'date',
    ];
}
