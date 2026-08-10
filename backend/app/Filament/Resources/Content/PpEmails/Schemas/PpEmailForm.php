<?php

namespace App\Filament\Resources\Content\PpEmails\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PpEmailForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subject')
                    ->required(),
                TextInput::make('date'),
            ]);
    }
}
