<?php

namespace App\Filament\Resources\Content\PpQuicklinks;

use App\Filament\Resources\Content\PpQuicklinks\Pages\CreatePpQuicklink;
use App\Filament\Resources\Content\PpQuicklinks\Pages\EditPpQuicklink;
use App\Filament\Resources\Content\PpQuicklinks\Pages\ListPpQuicklinks;
use App\Filament\Resources\Content\PpQuicklinks\Schemas\PpQuicklinkForm;
use App\Filament\Resources\Content\PpQuicklinks\Tables\PpQuicklinksTable;
use App\Models\Content\PpQuicklink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PpQuicklinkResource extends Resource
{
    protected static ?string $model = PpQuicklink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PpQuicklinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PpQuicklinksTable::configure($table);
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
            'index' => ListPpQuicklinks::route('/'),
            'create' => CreatePpQuicklink::route('/create'),
            'edit' => EditPpQuicklink::route('/{record}/edit'),
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
