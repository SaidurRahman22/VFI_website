<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3E — authorisation for the content collections (docs §4). Content roles
 * (content_editor + owner/superadmin) may read/write; other admin-panel staff
 * (counsellor, finance, partner-ops) cannot. Registered for all 10 collection
 * models; Filament enforces it on every resource action.
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
