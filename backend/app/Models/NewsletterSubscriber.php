<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An address captured by the footer subscribe box on the public site.
 * Unsubscribing sets a timestamp rather than deleting the row, so a later
 * re-subscribe cannot silently resurrect someone who opted out.
 */
class NewsletterSubscriber extends Model
{
    protected $fillable = ['email', 'interest', 'source_page', 'ip', 'unsubscribed_at'];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->unsubscribed_at === null;
    }
}
