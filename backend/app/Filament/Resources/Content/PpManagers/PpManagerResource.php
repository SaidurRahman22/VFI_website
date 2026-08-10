<?php

namespace App\Filament\Resources\Content\PpManagers;

use App\Filament\Resources\Content\PpManagers\Pages\CreatePpManager;
use App\Filament\Resources\Content\PpManagers\Pages\EditPpManager;
use App\Filament\Resources\Content\PpManagers\Pages\ListPpManagers;
use App\Filament\Resources\Content\PpManagers\Schemas\PpManagerForm;
use App\Filament\Resources\Content\PpManagers\Tables\PpManagersTable;
use App\Models\Content\PpManager;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PpManagerResource extends Resource
{
    protected static ?string $model = PpManager::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PpManagerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PpManagersTable::configure($table);
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
            'index' => ListPpManagers::route('/'),
            'create' => CreatePpManager::route('/create'),
            'edit' => EditPpManager::route('/{record}/edit'),
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
