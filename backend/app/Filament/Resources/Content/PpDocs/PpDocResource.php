<?php

namespace App\Filament\Resources\Content\PpDocs;

use App\Filament\Resources\Content\PpDocs\Pages\CreatePpDoc;
use App\Filament\Resources\Content\PpDocs\Pages\EditPpDoc;
use App\Filament\Resources\Content\PpDocs\Pages\ListPpDocs;
use App\Filament\Resources\Content\PpDocs\Schemas\PpDocForm;
use App\Filament\Resources\Content\PpDocs\Tables\PpDocsTable;
use App\Models\Content\PpDoc;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PpDocResource extends Resource
{
    protected static ?string $model = PpDoc::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PpDocForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PpDocsTable::configure($table);
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
            'index' => ListPpDocs::route('/'),
            'create' => CreatePpDoc::route('/create'),
            'edit' => EditPpDoc::route('/{record}/edit'),
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
