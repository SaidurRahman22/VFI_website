<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRole extends Model
{
    public $timestamps = false;   // uses granted_at / revoked_at instead

    protected $fillable = ['user_id', 'role', 'agency_id', 'granted_by', 'granted_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * App-layer mirror of the Postgres CHECK: tenant-bound roles must carry an
     * agency_id; non-tenant roles must not. Enforced on every write so the rule
     * holds on SQLite / MySQL too (docs §Role model).
     */
    protected static function booted(): void
    {
        static::saving(function (UserRole $r) {
            $role = $r->role instanceof Role ? $r->role : Role::from($r->role);
            if ($role->isTenantBound() && $r->agency_id === null) {
                throw new \InvalidArgumentException("Role {$role->value} requires an agency_id.");
            }
            if (! $role->isTenantBound() && $r->agency_id !== null) {
                throw new \InvalidArgumentException("Role {$role->value} must not carry an agency_id.");
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
