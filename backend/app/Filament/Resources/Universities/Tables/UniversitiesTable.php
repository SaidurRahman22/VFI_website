<?php

namespace App\Filament\Resources\Universities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UniversitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('programs'))
            ->columns([
                ImageColumn::make('logo_key')->label('Logo')->disk('public')->circular()->defaultImageUrl(null),
                TextColumn::make('name')->searchable()->sortable()->wrap()->weight('bold'),
                TextColumn::make('country')->searchable()->sortable(),
                TextColumn::make('city')->toggleable(),
                TextColumn::make('programs_count')->label('Programs')->sortable(),
                IconColumn::make('vfi_represented')->label('VFI')->boolean(),
                TextColumn::make('source')->badge()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
