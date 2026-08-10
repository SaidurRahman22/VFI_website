<?php

namespace App\Filament\Resources\Content\PpQuicklinks\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PpQuicklinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->required(),
                TextInput::make('url')
                    ->url(),
            ]);
    }
}
