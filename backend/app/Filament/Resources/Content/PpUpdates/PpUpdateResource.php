<?php

namespace App\Filament\Resources\Content\PpUpdates;

use App\Filament\Resources\Content\PpUpdates\Pages\CreatePpUpdate;
use App\Filament\Resources\Content\PpUpdates\Pages\EditPpUpdate;
use App\Filament\Resources\Content\PpUpdates\Pages\ListPpUpdates;
use App\Filament\Resources\Content\PpUpdates\Schemas\PpUpdateForm;
use App\Filament\Resources\Content\PpUpdates\Tables\PpUpdatesTable;
use App\Models\Content\PpUpdate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PpUpdateResource extends Resource
{
    protected static ?string $model = PpUpdate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PpUpdateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PpUpdatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPpUpdates::route('/'),
            'create' => CreatePpUpdate::route('/create'),
            'edit' => EditPpUpdate::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
