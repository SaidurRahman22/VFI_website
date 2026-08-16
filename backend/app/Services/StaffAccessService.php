<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\StaffAccessLog;
use App\Models\Student\Student;
use App\Models\User;
use RuntimeException;

/**
 * Phase 9A slice 5 — the audited door through the tenancy boundary.
 *
 * Everywhere else in this application, tenancy is absolute: the BelongsToAgency
 * scope is fail-closed and, on the tables that carry it, Postgres RLS is a
 * second independent net. But VFI staff genuinely do have to look at a student
 * who belongs to a partner agency — to answer a complaint, chase a document, or
 * fix a case.
 *
 * That legitimate need is exactly why it must be the narrowest possible door:
 *
 *   - only the staff roles allowed cross-tenant sight may open it;
 *   - a REASON is required, and it is recorded before anything is returned, so
 *     a read that fails to log cannot happen;
 *   - the subject's own tenant is recorded, so "who from VFI entered our
 *     records, and why" is answerable to the agency, not just to us.
 *
 * Deliberately NOT a general-purpose scope-off helper: it hands back one
 * specific record. Bulk cross-tenant listing stays with the queue screens, which
 * show operational fields only.
 */
class StaffAccessService
{
    /**
     * Roles permitted to read across tenants — CONFIRMED with the client:
     * superadmin + staff_counsellor.
     *
     * Read from config so the list can be tightened without a deploy, but the
     * fallback is the confirmed pair rather than "everything": a typo in the
     * env must narrow access, never widen it. Unknown role names are dropped.
     */
    private const DEFAULT_ROLES = [Role::SuperAdmin, Role::StaffCounsellor];

    /** @return list<Role> */
    private function allowedRoles(): array
    {
        $configured = (array) config('auth.cross_tenant_roles', []);
        if ($configured === []) {
            return self::DEFAULT_ROLES;
        }

        $roles = array_values(array_filter(array_map(
            fn ($v) => Role::tryFrom(trim((string) $v)),
            $configured,
        )));

        // never fail open: an unusable list falls back to the confirmed pair
        return $roles !== [] ? $roles : self::DEFAULT_ROLES;
    }

    public function mayReadAcrossTenants(User $staff): bool
    {
        foreach ($this->allowedRoles() as $role) {
            if ($staff->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Open one student's record from outside their tenant.
     * Returns the student only after the access has been recorded.
     */
    public function openStudent(User $staff, int $studentId, string $reason): Student
    {
        if (! $this->mayReadAcrossTenants($staff)) {
            throw new RuntimeException('Your account is not allowed to read records across agencies.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new RuntimeException('Give a specific reason for opening this record (at least 10 characters).');
        }

        // Students are guarded by the Eloquent scope only (no RLS policy), so
        // dropping the scope genuinely reaches the row. If a student table ever
        // gains an RLS policy, this needs a tenant-adopting read instead.
        $student = Student::withoutGlobalScopes()->find($studentId);
        if (! $student) {
            throw new RuntimeException('No such student.');
        }

        // Log BEFORE returning: the record is not "opened" until the reason is
        // durably written.
        StaffAccessLog::create([
            'actor_user_id' => $staff->id,
            'actor_email' => $staff->email,
            'subject_type' => 'student',
            'subject_id' => $student->id,
            'subject_agency_id' => $student->agency_id,
            'reason' => mb_substr($reason, 0, 500),
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255),
            'created_at' => now(),
        ]);

        return $student;
    }
}
