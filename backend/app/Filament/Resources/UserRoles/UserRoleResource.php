<?php

namespace App\Filament\Resources\UserRoles;

use App\Enums\Role;
use App\Filament\Resources\UserRoles\Pages\ListUserRoles;
use App\Filament\Resources\UserRoles\Tables\UserRolesTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 9A slice 4 — who holds which role.
 *
 * Superadmin-only, enforced in canAccess() so the whole resource (its route
 * included) is invisible and unreachable to anyone else — not merely hidden in
 * the navigation. Every write additionally re-checks the actor inside
 * RoleAssignmentService, so a forged request cannot slip past a UI-level check.
 */
class UserRoleResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Roles & access';

    protected static ?string $modelLabel = 'user';

    protected static ?int $navigationSort = 30;

    /** Gate the entire resource, not just the nav item. */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(Role::SuperAdmin) === true;
    }

    public static function table(Table $table): Table
    {
        return UserRolesTable::configure($table);
    }

    /** Eager-load the roles so the list is one query, not one per row. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserRoles::route('/'),
        ];
    }
}
