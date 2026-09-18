<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3E — authorisation for the content collections (docs §4). Content roles
 * (content_editor + owner/superadmin) may read/write; other admin-panel staff
 * (counsellor, finance, partner-ops) cannot. Registered for all 10 collection
 * models in AppServiceProvider.
 *
 * NOTHING ENFORCES THIS ON A REQUEST TODAY. The Filament resources that asked
 * the Gate on every action were deleted once the admin console started editing
 * these collections natively, and the console's API re-checks
 * StaffAbilities::current('content.manage') inside each handler instead — see
 * AdminContentCollectionController::authorise(). So do not read a method here
 * as proof that some endpoint is guarded; go and read the endpoint.
 *
 * Kept rather than deleted because it is the only place the content read/write
 * split is stated as a rule instead of repeated per handler: RoleSplitTest
 * asserts that rule through the Gate, and anything reaching for authorize() or
 * ->can() on a collection model later gets the same answer as the API without
 * anyone having to restate it.
 */
class ContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canEditContent();
    }

    public function view(User $user, Model $model): bool
    {
        return $user->canEditContent();
    }

    public function create(User $user): bool
    {
        return $user->canEditContent();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->canEditContent();
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->canEditContent();
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->canEditContent();
    }

    public function forceDelete(User $user, Model $model): bool
    {
        // hard delete is an owner-only escalation
        return $user->isOwner();
    }
}
