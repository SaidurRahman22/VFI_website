<?php

namespace App\Filament\Resources\Content\PpUpdates\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PpUpdateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('flag'),
                TextInput::make('title')
                    ->required(),
                TextInput::make('sub'),
                TextInput::make('date'),
            ]);
    }
}
