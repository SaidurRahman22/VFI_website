<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ContentAuditLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9A slice 4 — grant and revoke roles.
 *
 * The columns (granted_by / granted_at / revoked_at) existed since Phase 1 with
 * no surface at all. This is the write path, and it is the most dangerous one in
 * the back-office: roles are what the entire authorisation model reads, so the
 * guards here are the product, not decoration.
 *
 * Guards:
 *   - only a superadmin may change roles at all;
 *   - nobody can drop their OWN superadmin (locking yourself out mid-task);
 *   - the LAST superadmin can never be removed — enforced by counting inside a
 *     locked transaction, not by a UI check, so two concurrent revokes cannot
 *     race the system into having zero owners;
 *   - tenant-bound roles require an agency, global roles must not carry one.
 *
 * Revocation is soft (revoked_at) so the history of who held what, and when,
 * survives — `activeRoles()` already filters on it.
 */
class RoleAssignmentService
{
    public function grant(User $target, Role $role, ?int $agencyId, User $actor): UserRole
    {
        $this->assertActorMayManageRoles($actor);

        if ($role->isTenantBound() && $agencyId === null) {
            throw new RuntimeException("The {$role->value} role belongs to an agency — pick one.");
        }
        if (! $role->isTenantBound() && $agencyId !== null) {
            throw new RuntimeException("The {$role->value} role is global and cannot be tied to an agency.");
        }

        return DB::transaction(function () use ($target, $role, $agencyId, $actor) {
            $existing = UserRole::where('user_id', $target->id)
                ->where('role', $role->value)
                ->where('agency_id', $agencyId)
                ->first();

            if ($existing && $existing->revoked_at === null) {
                throw new RuntimeException('This user already holds that role.');
            }

            if ($existing) {
                $existing->forceFill(['revoked_at' => null, 'granted_at' => now(), 'granted_by' => $actor->id])->save();
                $row = $existing;
            } else {
                $row = UserRole::create([
                    'user_id' => $target->id, 'role' => $role->value, 'agency_id' => $agencyId,
                    'granted_by' => $actor->id, 'granted_at' => now(),
                ]);
            }

            ContentAuditLog::record('role_grant', 'user', (string) $target->id,
                ['role' => $role->value, 'agency_id' => $agencyId, 'held' => false],
                ['role' => $role->value, 'agency_id' => $agencyId, 'held' => true, 'actor_user_id' => $actor->id],
            );

            return $row;
        });
    }

    public function revoke(UserRole $assignment, User $actor): void
    {
        $this->assertActorMayManageRoles($actor);

        if ($assignment->revoked_at !== null) {
            throw new RuntimeException('That role has already been revoked.');
        }

        $role = $assignment->role instanceof Role ? $assignment->role : Role::from($assignment->role);

        if ($role === Role::SuperAdmin && (int) $assignment->user_id === (int) $actor->id) {
            throw new RuntimeException('You cannot remove your own superadmin role.');
        }

        DB::transaction(function () use ($assignment, $role, $actor) {
            if ($role === Role::SuperAdmin) {
                // Count inside the transaction with the rows locked, so two
                // concurrent revokes cannot both believe another owner remains.
                //
                // Selected and counted in PHP rather than with ->count(), because
                // ->lockForUpdate()->count() emits `SELECT count(*) ... FOR UPDATE`
                // and Postgres rejects that outright:
                //   SQLSTATE[0A000] FOR UPDATE is not allowed with aggregate functions
                // SQLite accepts it and ignores the lock, so this guard passed every
                // test while throwing a 500 on production instead of protecting the
                // last superadmin. Only ids are selected, and the set is tiny.
                $remaining = UserRole::where('role', Role::SuperAdmin->value)
                    ->whereNull('revoked_at')
                    ->where('id', '!=', $assignment->id)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->count();

                if ($remaining === 0) {
                    throw new RuntimeException('This is the last superadmin — grant another before removing this one.');
                }
            }

            $assignment->forceFill(['revoked_at' => now()])->save();

            ContentAuditLog::record('role_revoke', 'user', (string) $assignment->user_id,
                ['role' => $role->value, 'agency_id' => $assignment->agency_id, 'held' => true],
                ['role' => $role->value, 'agency_id' => $assignment->agency_id, 'held' => false, 'actor_user_id' => $actor->id],
            );
        });
    }

    private function assertActorMayManageRoles(User $actor): void
    {
        if (! $actor->hasRole(Role::SuperAdmin)) {
            throw new RuntimeException('Only a superadmin can change roles.');
        }
    }
}
