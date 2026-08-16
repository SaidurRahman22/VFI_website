<?php

namespace App\Filament\Resources\Content\Blogs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BlogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('category'),
                DatePicker::make('date'),
                Textarea::make('excerpt')
                    ->columnSpanFull(),
                TextInput::make('color'),
                TextInput::make('img_id'),
                TextInput::make('author'),
                TextInput::make('read_time'),
                Textarea::make('body')
                    ->columnSpanFull(),
            ]);
    }
}
