<?php

namespace App\Filament\Resources\Agencies;

use App\Filament\Resources\Agencies\Pages\ListAgencies;
use App\Filament\Resources\Agencies\Tables\AgenciesTable;
use App\Models\Partner\PartnerAgency;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 9A slice 3 — the roster of partner agencies, with suspend / close /
 * reinstate. Read-only as a resource: agencies are created by approving a
 * partner application, never typed in here, and status only ever changes
 * through AgencySuspensionService so the reason and audit are guaranteed.
 */
class AgencyResource extends Resource
{
    protected static ?string $model = PartnerAgency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Agencies';

    protected static ?string $modelLabel = 'agency';

    protected static ?int $navigationSort = 12;

    public static function table(Table $table): Table
    {
        return AgenciesTable::configure($table);
    }

    /**
     * Aggregated in the base query rather than per row.
     *
     * Only the applications count. Both tables are RLS-protected, but the
     * applications policy now also admits a read when `app.rls_bypass` is on,
     * which the admin panel sets for authenticated requests
     * (App\Http\Middleware\StaffRlsRead). `partner_agency_members` deliberately
     * keeps no such bypass, so a staff COUNT there would return 0 on Postgres
     * whatever the Eloquent scope did — and a silently wrong number is worse
     * than no number.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('applicationsAll');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgencies::route('/'),
        ];
    }
}
