<?php

namespace App\Filament\Resources\Content\Photos;

use App\Filament\Resources\Content\Photos\Pages\CreatePhoto;
use App\Filament\Resources\Content\Photos\Pages\EditPhoto;
use App\Filament\Resources\Content\Photos\Pages\ListPhotos;
use App\Filament\Resources\Content\Photos\Schemas\PhotoForm;
use App\Filament\Resources\Content\Photos\Tables\PhotosTable;
use App\Models\Content\Photo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PhotoResource extends Resource
{
    protected static ?string $model = Photo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PhotoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhotosTable::configure($table);
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
            'index' => ListPhotos::route('/'),
            'create' => CreatePhoto::route('/create'),
            'edit' => EditPhoto::route('/{record}/edit'),
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
