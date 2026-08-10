<?php

namespace App\Models\Partner;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/** Phase 7 — a tenant-scoped console notification (page + bell share this). */
class PartnerNotification extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'kind', 'title', 'body', 'link', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
