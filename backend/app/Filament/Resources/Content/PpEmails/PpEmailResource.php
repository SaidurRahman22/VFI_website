<?php

namespace App\Filament\Resources\Content\PpEmails;

use App\Filament\Resources\Content\PpEmails\Pages\CreatePpEmail;
use App\Filament\Resources\Content\PpEmails\Pages\EditPpEmail;
use App\Filament\Resources\Content\PpEmails\Pages\ListPpEmails;
use App\Filament\Resources\Content\PpEmails\Schemas\PpEmailForm;
use App\Filament\Resources\Content\PpEmails\Tables\PpEmailsTable;
use App\Models\Content\PpEmail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PpEmailResource extends Resource
{
    protected static ?string $model = PpEmail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PpEmailForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PpEmailsTable::configure($table);
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
            'index' => ListPpEmails::route('/'),
            'create' => CreatePpEmail::route('/create'),
            'edit' => EditPpEmail::route('/{record}/edit'),
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
