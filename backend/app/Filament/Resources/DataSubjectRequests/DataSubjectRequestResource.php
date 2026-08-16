<?php

namespace App\Filament\Resources\DataSubjectRequests;

use App\Enums\Role;
use App\Filament\Resources\DataSubjectRequests\Pages\ListDataSubjectRequests;
use App\Filament\Resources\DataSubjectRequests\Tables\DataSubjectRequestsTable;
use App\Models\DataSubjectRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 9B — the register of GDPR export and erasure requests.
 *
 * A regulator asks to see the register, not the code, so the whole lifecycle has
 * to be operable here: raise a request from the toolbar, watch the outcome
 * land on the row, download the bundle. Rows that were blocked by a legal hold
 * or that failed stay visible — a register of successes only is not a register.
 *
 * Read-only as a resource (no create/edit form pages): requests are only ever
 * written by the GDPR services, so every one of them goes down a single guarded,
 * audited path. The toolbar and row actions are the entire write surface.
 *
 * Superadmin-only, enforced in canAccess() so the route is unreachable rather
 * than merely hidden — this screen can both read someone's whole record and
 * destroy it.
 */
class DataSubjectRequestResource extends Resource
{
    protected static ?string $model = DataSubjectRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'GDPR requests';

    protected static ?string $modelLabel = 'GDPR request';

    protected static ?string $pluralModelLabel = 'GDPR requests';

    protected static ?int $navigationSort = 40;

    /** Gate the entire resource, not just the nav item. */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(Role::SuperAdmin) === true;
    }

    public static function table(Table $table): Table
    {
        return DataSubjectRequestsTable::configure($table);
    }

    /** Eager-load the requester so the list is one query, not one per row. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('requestedBy:id,email');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataSubjectRequests::route('/'),
        ];
    }
}
