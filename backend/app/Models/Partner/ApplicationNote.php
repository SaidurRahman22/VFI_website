<?php

namespace App\Models\Partner;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 9A — a STAFF-INTERNAL note on an application.
 *
 * NOT tenant-scoped on purpose: these are VFI's own working notes about an
 * application, not agency-owned data, and only the admin panel ever reads them.
 * They are never included in any partner or student response — if you find
 * yourself serialising this model in a Partner\* or Me\* controller, that is a
 * leak, not a feature.
 *
 * Append-only: no updated_at, no edit path. Corrections are new notes.
 */
/**
 * @property int $id
 * @property int $application_id
 * @property int|null $author_user_id
 * @property string|null $author_name
 * @property string $body
 * @property-read User|null $author
 * @property-read Application|null $application
 * @property Carbon|null $created_at
 */
class ApplicationNote extends Model
{
    public $timestamps = false;   // created_at is set explicitly; never updated

    protected $fillable = ['application_id', 'author_user_id', 'author_name', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
