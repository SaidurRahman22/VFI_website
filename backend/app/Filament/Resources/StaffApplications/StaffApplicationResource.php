<?php

namespace App\Filament\Resources\StaffApplications;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Filament\Resources\StaffApplications\Tables\StaffApplicationsTable;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Support\RlsBypass;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 9A slice 2 — the staff view of every agency's applications.
 *
 * Cross-tenant by necessity: VFI staff process cases for all partner agencies.
 * TWO nets have to be stood down for that, and missing either one returns an
 * empty screen rather than an error:
 *   1. the fail-closed BelongsToAgency Eloquent scope, dropped explicitly here;
 *   2. Postgres RLS FORCE on `applications` — which applies even to the table
 *      owner — handled by reading inside RlsBypass::run().
 * Only reads are admitted this way. Every WRITE still goes through
 * ApplicationReviewService, which adopts the owning tenant, because the
 * policies' WITH CHECK carries no bypass by design.
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
            ->with(['student:id,first_name,last_name,email', 'agency:id,legal_name'])
            ->withCount('notes');                               // avoids a count per row
    }

    /**
     * Cases waiting on VFI (not on the partner) — the actual work queue.
     * Cached: this runs on EVERY admin page render, and at scale an
     * unindexed-status COUNT on every request is exactly the kind of load the
     * panel does not need. 60s is fresh enough for a queue badge.
     */
    public static function getNavigationBadge(): ?string
    {
        $n = Cache::remember('badge:staff-applications', 60, fn () => RlsBypass::run(
            fn () => static::getModel()::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->whereIn('status', [ApplicationStatus::Submitted->value, ApplicationStatus::Review->value])
                ->count()
        ));

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
