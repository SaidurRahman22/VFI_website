<?php

namespace App\Filament\Resources\Content\PpUpdates\Pages;

use App\Filament\Resources\Content\PpUpdates\PpUpdateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPpUpdates extends ListRecords
{
    protected static string $resource = PpUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
