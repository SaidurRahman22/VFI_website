<?php

namespace App\Filament\Resources\StaffApplications;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Filament\Resources\StaffApplications\Tables\StaffApplicationsTable;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 9A slice 2 — the staff view of every agency's applications.
 *
 * Cross-tenant by necessity: VFI staff process cases for all partner agencies,
 * and the BelongsToAgency scope is fail-closed, so it is dropped here
 * explicitly. That opt-out is exactly what the trait's docblock prescribes, and
 * it is safe for this table specifically because `applications` is guarded by
 * the Eloquent scope only (no RLS policy) — every WRITE still goes through
 * ApplicationReviewService, which adopts the owning tenant before touching
 * tenant-owned rows.
 *
 * Read-only as a resource: no create/edit form. The row actions are the whole
 * write surface, so every status move passes the transition guard.
 */
class StaffApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Applications';

    protected static ?string $modelLabel = 'application';

    protected static ?int $navigationSort = 11;

    public static function table(Table $table): Table
    {
        return StaffApplicationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(BelongsToAgencyScope::class)   // staff act across tenants
            ->with(['student', 'agency']);
    }

    /** Cases waiting on VFI (not on the partner) — the actual work queue. */
    public static function getNavigationBadge(): ?string
    {
        $n = static::getModel()::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->whereIn('status', [ApplicationStatus::Submitted->value, ApplicationStatus::Review->value])
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffApplications::route('/'),
        ];
    }
}
