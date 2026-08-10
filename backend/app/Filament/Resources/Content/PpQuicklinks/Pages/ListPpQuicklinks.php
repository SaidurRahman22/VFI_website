<?php

namespace App\Filament\Resources\Content\PpQuicklinks\Pages;

use App\Filament\Resources\Content\PpQuicklinks\PpQuicklinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPpQuicklinks extends ListRecords
{
    protected static string $resource = PpQuicklinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
