<?php

namespace App\Filament\Resources\Content\PpDocs\Pages;

use App\Filament\Resources\Content\PpDocs\PpDocResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPpDocs extends ListRecords
{
    protected static string $resource = PpDocResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
