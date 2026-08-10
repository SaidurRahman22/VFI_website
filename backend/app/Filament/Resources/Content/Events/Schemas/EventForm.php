<?php

namespace App\Filament\Resources\Content\Events\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                DatePicker::make('date'),
                TextInput::make('time'),
                TextInput::make('type'),
                TextInput::make('city'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('color'),
                TextInput::make('img_id'),
            ]);
    }
}
