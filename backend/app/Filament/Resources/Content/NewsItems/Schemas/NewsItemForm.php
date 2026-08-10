<?php

namespace App\Filament\Resources\Content\NewsItems\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class NewsItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('color'),
                TextInput::make('img_id'),
                Textarea::make('excerpt')
                    ->columnSpanFull(),
            ]);
    }
}
