<?php

namespace App\Filament\Resources\Content\PpDocs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PpDocForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('country'),
                TextInput::make('category'),
                TextInput::make('title')
                    ->required(),
                TextInput::make('date'),
                TextInput::make('size'),
                TextInput::make('url')
                    ->url(),
            ]);
    }
}
