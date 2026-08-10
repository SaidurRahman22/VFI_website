<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key→JSONB store for the override singletons + maps (Phase 2B §2):
 * settings, countries, regions, servicesPage, partnerPage, partnerConsoleText,
 * media, pages. Values round-trip ""/[] faithfully — no default substitution.
 */
class SiteContent extends Model
{
    protected $table = 'site_content';

    protected $fillable = ['key', 'value', 'version'];

    protected $casts = ['value' => 'array'];

    /** Fetch a singleton's value, or $default if the key is absent. */
    public static function value(string $key, $default = null)
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? $row->value : $default;
    }
}
