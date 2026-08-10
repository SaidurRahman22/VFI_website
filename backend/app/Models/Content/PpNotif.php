<?php

namespace App\Models\Content;

class PpNotif extends ContentItem
{
    protected $table = 'pp_notifs';

    protected $fillable = ['legacy_id', 'position', 'title', 'message', 'date'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'title' => 'title', 'message' => 'text', 'date' => 'date',
    ];
}
