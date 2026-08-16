<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AdminInvite;
use App\Models\AuthEvent;
use App\Models\User;
use App\Models\UserRole;
use App\Support\StaffAbilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin account lifecycle (docs §6): sealed superadmin bootstrap, invite-only
 * creation (no sign-up UI, ever), and self-demotion protection so the last
 * superadmin can never lock everyone out.
 */
class AdminAccounts
{
    /** Bootstrap the first superadmin. Sealed: refuses if one already exists. */
    public function createSuperAdmin(string $email, string $password): User
    {
        $email = mb_strtolower(trim($email));

        if ($this->superAdminCount() > 0) {
            throw new \RuntimeException('A superadmin already exists; bootstrap is sealed. Use an invite.');
        }

        return DB::transaction(function () use ($email, $password) {
            $user = User::create([
                'name' => 'Superadmin',
                'email' => $email,
                'password' => $password,          // hashed by cast
            ]);
            $user->forceFill(['status' => UserStatus::Active])->save();

            UserRole::create([
                'user_id' => $user->id,
                'role' => Role::SuperAdmin,
                'agency_id' => null,
                'granted_at' => now(),
            ]);

            AuthEvent::record('superadmin_bootstrapped', ['user_id' => $user->id, 'email' => $email]);

            return $user;
        });
    }

    /**
     * Superadmin creates a staff account directly, with its starting role.
     *
     * The invite flow below is the nicer path, but it needs email — which is
     * still on the `log` driver, so an invite would never arrive. This creates
     * the account now and returns a one-time password to hand over out of band.
     *
     * @return array{user: User, password: string}
     */
    public function createStaff(User $actor, string $name, string $email, Role $role): array
    {
        if (! $actor->isSuperAdmin()) {
            throw new \RuntimeException('Only a superadmin can create staff accounts.');
        }
        if (! in_array($role, StaffAbilities::ASSIGNABLE, true)) {
            throw new \RuntimeException('That role cannot be assigned here.');
        }

        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('A name is required.');
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw new \RuntimeException('An account with that email already exists — grant it a role instead.');
        }

        // 18 URL-safe characters: long enough that it never needs a complexity
        // rule, and it is replaced by the holder on first sign-in anyway.
        $password = Str::random(18);

        return DB::transaction(function () use ($name, $email, $role, $password, $actor) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,          // hashed by the model cast
            ]);
            $user->forceFill(['status' => UserStatus::Active])->save();

            UserRole::create([
                'user_id' => $user->id,
                'role' => $role,
                'agency_id' => null,              // staff roles are never tenant-bound
                'granted_by' => $actor->id,
                'granted_at' => now(),
            ]);

            AuthEvent::record('staff_account_created', [
                'user_id' => $actor->id,          // who did it, not who it is
                'email' => $email,
                'context' => ['role' => $role->value, 'new_user_id' => $user->id],
            ]);

            return ['user' => $user, 'password' => $password];
        });
    }

    /** Superadmin issues an expiring, single-use invite; returns the RAW token (shown once). */
    public function issueInvite(User $inviter, string $email, Role $role, int $ttlHours = 48): array
    {
        if (! $inviter->isSuperAdmin()) {
            throw new \RuntimeException('Only a superadmin may issue admin invites.');
        }
        if (! $role->usesAdminPanel()) {
            throw new \InvalidArgumentException('Invites are for admin-panel roles only.');
        }

        $raw = Str::random(48);
        $invite = AdminInvite::create([
            'email' => mb_strtolower(trim($email)),
            'role' => $role,
            'token_hash' => AdminInvite::hashToken($raw),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addHours($ttlHours),
        ]);

        AuthEvent::record('admin_invite_issued', [
            'user_id' => $inviter->id, 'email' => $invite->email, 'context' => ['role' => $role->value],
        ]);

        return ['invite' => $invite, 'token' => $raw];
    }

    /** Accept an invite: create the user + role, single-use. */
    public function acceptInvite(string $rawToken, string $name, string $password): User
    {
        $invite = AdminInvite::where('token_hash', AdminInvite::hashToken($rawToken))->first();
        if (! $invite || ! $invite->isUsable()) {
            throw new \RuntimeException('Invite is invalid, used, or expired.');
        }

        return DB::transaction(function () use ($invite, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invite->email,
                'password' => $password,
            ]);
            $user->forceFill(['status' => UserStatus::Active])->save();

            UserRole::create([
                'user_id' => $user->id,
                'role' => $invite->role,
                'agency_id' => null,
                'granted_at' => now(),
            ]);

            $invite->forceFill(['accepted_at' => now()])->save();
            AuthEvent::record('admin_invite_accepted', ['user_id' => $user->id, 'email' => $user->email]);

            return $user;
        });
    }

    /**
     * Revoke a role. Self-demotion protection: the LAST superadmin cannot lose
     * its superadmin role (docs §6.3) — otherwise no one could ever administer.
     */
    public function revokeRole(User $user, Role $role): void
    {
        if ($role === Role::SuperAdmin && $user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            throw new \RuntimeException('Cannot remove the last superadmin.');
        }

        UserRole::where('user_id', $user->id)->where('role', $role->value)
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);

        AuthEvent::record('role_revoked', ['user_id' => $user->id, 'context' => ['role' => $role->value]]);
    }

    /** Deleting an account is also blocked if it's the last superadmin. */
    public function deleteAdmin(User $user): void
    {
        if ($user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            throw new \RuntimeException('Cannot delete the last superadmin.');
        }
        $user->delete();
        AuthEvent::record('admin_deleted', ['user_id' => $user->id]);
    }

    private function superAdminCount(): int
    {
        return UserRole::where('role', Role::SuperAdmin->value)->whereNull('revoked_at')->count();
    }
}
