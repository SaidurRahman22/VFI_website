<?php

namespace App\Models\Content;

class PpManager extends ContentItem
{
    protected $table = 'pp_managers';

    protected $fillable = ['legacy_id', 'position', 'name', 'role', 'phone', 'city', 'email'];

    protected array $bundleMap = [
        'legacy_id' => 'id', 'name' => 'name', 'role' => 'role',
        'phone' => 'phone', 'city' => 'city', 'email' => 'email',
    ];
}
