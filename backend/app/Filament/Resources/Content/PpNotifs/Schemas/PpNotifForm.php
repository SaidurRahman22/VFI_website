<?php

namespace App\Filament\Resources\Content\PpNotifs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PpNotifForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                Textarea::make('message')
                    ->columnSpanFull(),
                TextInput::make('date'),
            ]);
    }
}
