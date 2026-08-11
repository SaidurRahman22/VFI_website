<?php

namespace App\Filament\Resources\Universities;

use App\Filament\Resources\Universities\Pages\CreateUniversity;
use App\Filament\Resources\Universities\Pages\EditUniversity;
use App\Filament\Resources\Universities\Pages\ListUniversities;
use App\Filament\Resources\Universities\Schemas\UniversityForm;
use App\Filament\Resources\Universities\Tables\UniversitiesTable;
use App\Models\Catalogue\Institution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Phase 8+ — staff CRUD for universities (catalogue institutions + their
 * editorial profile). Lives in the /manage admin panel; a new university can be
 * created here or an ingested one (US/DE) enriched with logo, ranking,
 * scholarships, gallery, FAQs etc. that the public detail page renders.
 */
class UniversityResource extends Resource
{
    protected static ?string $model = Institution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'University';

    protected static ?string $pluralModelLabel = 'Universities';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return UniversityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UniversitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUniversities::route('/'),
            'create' => CreateUniversity::route('/create'),
            'edit' => EditUniversity::route('/{record}/edit'),
        ];
    }
}
