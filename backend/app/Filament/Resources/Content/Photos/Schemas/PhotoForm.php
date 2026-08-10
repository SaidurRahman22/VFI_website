<?php

namespace App\Filament\Resources\Content\Photos\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PhotoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('img_id'),
                TextInput::make('caption'),
                TextInput::make('alt'),
            ]);
    }
}
