<?php

namespace App\Filament\Resources\Content\PpNotifs;

use App\Filament\Resources\Content\PpNotifs\Pages\CreatePpNotif;
use App\Filament\Resources\Content\PpNotifs\Pages\EditPpNotif;
use App\Filament\Resources\Content\PpNotifs\Pages\ListPpNotifs;
use App\Filament\Resources\Content\PpNotifs\Schemas\PpNotifForm;
use App\Filament\Resources\Content\PpNotifs\Tables\PpNotifsTable;
use App\Models\Content\PpNotif;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PpNotifResource extends Resource
{
    protected static ?string $model = PpNotif::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PpNotifForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PpNotifsTable::configure($table);
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
            'index' => ListPpNotifs::route('/'),
            'create' => CreatePpNotif::route('/create'),
            'edit' => EditPpNotif::route('/{record}/edit'),
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
