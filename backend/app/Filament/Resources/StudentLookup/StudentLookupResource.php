<?php

namespace App\Filament\Resources\StudentLookup;

use App\Filament\Resources\StudentLookup\Pages\ListStudentLookup;
use App\Filament\Resources\StudentLookup\Tables\StudentLookupTable;
use App\Models\Student\Student;
use App\Services\StaffAccessService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 9A slice 5 — cross-tenant student lookup.
 *
 * Gated in canAccess() to the roles allowed cross-tenant sight, so the route is
 * unreachable — not merely hidden — for everyone else.
 *
 * The LIST deliberately shows operational fields only (reference, agency,
 * created date): enough to find the right person, not enough to be a bulk export
 * of other agencies' students. Personal detail is behind the per-record "Open"
 * action, which demands a reason and logs it first.
 */
class StudentLookupResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'Student lookup';

    protected static ?string $modelLabel = 'student';

    protected static ?int $navigationSort = 31;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(StaffAccessService::class)->mayReadAcrossTenants($user);
    }

    public static function table(Table $table): Table
    {
        return StudentLookupTable::configure($table);
    }

    /**
     * Cross-tenant by design. Students are guarded by the Eloquent scope only,
     * so the opt-out genuinely reaches every row — see StaffAccessService.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->with('agency:id,legal_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentLookup::route('/'),
        ];
    }
}
