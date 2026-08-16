<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'mfa_secret'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Filament access gate (Phase 1 §4). Even an authenticated web session can
     * only enter the panel with an admin-panel role AND completed TOTP — the
     * same bar as /api/admin. Partner/student sessions are refused.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $mfaOk = ! config('auth.admin_require_totp', true) || $this->hasMfa();

        return $this->usesAdminPanel()
            && $mfaOk
            && $this->status === UserStatus::Active
            && ! $this->isLocked();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'mfa_secret' => 'encrypted',          // TOTP secret encrypted at rest
            'mfa_enrolled_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    /** Case-insensitive email: always stored lowercased/trimmed (portable citext). */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /** @return Collection<int,Role> active (non-revoked) roles */
    public function activeRoles()
    {
        return $this->roles->whereNull('revoked_at')->map(fn (UserRole $r) => $r->role);
    }

    public function hasRole(Role $role): bool
    {
        return $this->roles->whereNull('revoked_at')->contains('role', $role);
    }

    /** Any role that uses the admin panel → TOTP-required, /api/admin scope. */
    public function usesAdminPanel(): bool
    {
        return $this->activeRoles()->contains(fn (Role $r) => $r->usesAdminPanel());
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SuperAdmin);
    }

    /** "Owner" privileged operations (backup, page-toggle, admin users) = superadmin. */
    public function isOwner(): bool
    {
        return $this->isSuperAdmin();
    }

    /** May edit content (collections, singleton text, media): content_editor OR owner. */
    public function canEditContent(): bool
    {
        return $this->isSuperAdmin() || $this->hasRole(Role::ContentEditor);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasMfa(): bool
    {
        return $this->mfa_enrolled_at !== null && ! empty($this->mfa_secret);
    }

    public function markLoginSuccess(): void
    {
        $this->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();
    }

    /** Progressive lockout: lock for 15 min once failures cross the threshold. */
    public function registerFailedLogin(int $threshold = 10, int $lockMinutes = 15): void
    {
        $count = $this->failed_login_count + 1;
        $this->forceFill([
            'failed_login_count' => $count,
            'locked_until' => $count >= $threshold ? Date::now()->addMinutes($lockMinutes) : $this->locked_until,
        ])->save();
    }
}
