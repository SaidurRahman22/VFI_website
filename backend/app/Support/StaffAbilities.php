<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * What each staff role is allowed to do in the admin panel.
 *
 * Until now every screen in /manage was reachable by anyone holding ANY
 * admin-panel role, so a content editor could process applications, suspend an
 * agency or open another agency's student. Roles existed but decided nothing.
 *
 * This is the single place that answers "may this person do X". Each Filament
 * resource asks it in canAccess(), so a role change takes effect everywhere at
 * once and there is one list to review rather than a gate copied per screen.
 *
 * Deny by default: an ability that is not listed here is superadmin-only.
 */
class StaffAbilities
{
    /** ability => the roles that hold it (superadmin is implicit everywhere). */
    private const MAP = [
        // process a student's application through the pipeline
        'applications.process' => [Role::StaffCounsellor, Role::StaffPartnerOps],
        // verify or reject uploaded documents
        'documents.review' => [Role::StaffCounsellor, Role::StaffPartnerOps],
        // approve / reject agency signups, suspend or close an agency
        'agencies.manage' => [Role::StaffPartnerOps],
        // read a student who belongs to a partner agency (reason recorded)
        'students.crossTenant' => [Role::StaffCounsellor],
        // the university catalogue and its editorial content
        'catalogue.manage' => [Role::ContentEditor],
        // website content collections
        'content.manage' => [Role::ContentEditor],
        // contact-form enquiries
        'enquiries.view' => [Role::StaffCounsellor, Role::StaffPartnerOps],
    ];

    /** Roles a superadmin may hand out when creating a staff account. */
    public const ASSIGNABLE = [
        Role::StaffCounsellor,
        Role::StaffPartnerOps,
        Role::StaffFinance,
        Role::ContentEditor,
    ];

    public static function allows(?User $user, string $ability): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasRole(Role::SuperAdmin)) {
            return true;   // the owner role holds everything
        }

        foreach (self::MAP[$ability] ?? [] as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every ability, resolved for one user.
     *
     * The admin panel's navigation has to know which screens a person may open,
     * and the only safe way to tell it is to answer here. The alternative -
     * shipping the role-to-ability map to the browser and evaluating it there -
     * would mean two copies of the permission rules, and the client copy would
     * drift the first time a role changed. The server stays the only place that
     * decides; the client only decides what to DRAW.
     *
     * This is not an authorisation boundary. Hiding a link is a courtesy, and
     * every screen still refuses the request on its own.
     *
     * @return array<string, bool>
     */
    public static function forUser(?User $user): array
    {
        $out = [];
        foreach (array_keys(self::MAP) as $ability) {
            $out[$ability] = self::allows($user, $ability);
        }

        return $out;
    }

    /** Convenience for Filament canAccess(). */
    public static function current(string $ability): bool
    {
        return self::allows(auth()->user(), $ability);
    }

    /** Plain-English summary of a role, for the admin UI. */
    public static function describe(Role $role): string
    {
        return match ($role) {
            Role::StaffCounsellor => 'Process applications, review documents, and open a student’s record across agencies (with a recorded reason).',
            Role::StaffPartnerOps => 'Process applications, review documents, approve agency signups, and suspend or close an agency.',
            Role::StaffFinance => 'Finance role. Reserved for the wallet and payments features; grants no admin screens yet.',
            Role::ContentEditor => 'Edit website content and the university catalogue. No access to students or applications.',
            Role::SuperAdmin => 'Full access, including creating staff accounts and changing roles.',
            default => 'No admin access.',
        };
    }
}
