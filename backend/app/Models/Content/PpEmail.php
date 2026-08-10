<?php

namespace App\Models\Content;

class PpEmail extends ContentItem
{
    protected $table = 'pp_emails';

    protected $fillable = ['legacy_id', 'position', 'subject', 'date'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'subject' => 'subject', 'date' => 'date',
    ];
}
