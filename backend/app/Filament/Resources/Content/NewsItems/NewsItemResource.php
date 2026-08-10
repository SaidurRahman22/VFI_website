<?php

namespace App\Filament\Resources\Content\NewsItems;

use App\Filament\Resources\Content\NewsItems\Pages\CreateNewsItem;
use App\Filament\Resources\Content\NewsItems\Pages\EditNewsItem;
use App\Filament\Resources\Content\NewsItems\Pages\ListNewsItems;
use App\Filament\Resources\Content\NewsItems\Schemas\NewsItemForm;
use App\Filament\Resources\Content\NewsItems\Tables\NewsItemsTable;
use App\Models\Content\NewsItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class NewsItemResource extends Resource
{
    protected static ?string $model = NewsItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return NewsItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NewsItemsTable::configure($table);
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
            'index' => ListNewsItems::route('/'),
            'create' => CreateNewsItem::route('/create'),
            'edit' => EditNewsItem::route('/{record}/edit'),
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
