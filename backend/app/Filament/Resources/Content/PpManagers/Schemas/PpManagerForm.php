<?php

namespace App\Filament\Resources\Content\PpManagers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PpManagerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('role'),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('city'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
            ]);
    }
}
