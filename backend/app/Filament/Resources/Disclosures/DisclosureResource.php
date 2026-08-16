<?php

namespace App\Filament\Resources\Disclosures;

use App\Filament\Resources\Disclosures\Pages\ListDisclosures;
use App\Filament\Resources\Disclosures\Tables\DisclosuresTable;
use App\Models\DocumentDisclosure;
use App\Services\StaffAccessService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 9B — the onward-disclosure register: which document left VFI, to whom,
 * when, and on what lawful basis.
 *
 * Deliberately not the same thing as the document access log. That records who
 * VIEWED a file inside the system; this records who RECEIVED it outside. Only
 * the second is what a data subject is entitled to be told about, and only the
 * second is what a university or lender query is answered from.
 *
 * Append-only in the model, so the resource is read-plus-record: rows can be
 * added and read, never edited away.
 *
 * Gated to the roles allowed cross-tenant sight — a disclosure register spans
 * every agency's students by nature, so it cannot be tenant-scoped browsing.
 */
class DisclosureResource extends Resource
{
    protected static ?string $model = DocumentDisclosure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $navigationLabel = 'Disclosures';

    protected static ?string $modelLabel = 'disclosure';

    protected static ?int $navigationSort = 41;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(StaffAccessService::class)->mayReadAcrossTenants($user);
    }

    public static function table(Table $table): Table
    {
        return DisclosuresTable::configure($table);
    }

    /**
     * Eager-load everything the list renders, so a page of rows is a handful of
     * queries rather than four per row. Only the columns actually shown are
     * selected — this list touches student PII and should carry no more of it
     * than it displays.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'student:id,email',
            'file:id,original_name',
            'disclosedBy:id,email',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisclosures::route('/'),
        ];
    }
}
