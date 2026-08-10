<?php

namespace App\Filament\Resources\Content\NewsItems\Pages;

use App\Filament\Resources\Content\NewsItems\NewsItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsItems extends ListRecords
{
    protected static string $resource = NewsItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
