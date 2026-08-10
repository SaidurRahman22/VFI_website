<?php

namespace App\Filament\Resources\Content\PpNotifs\Pages;

use App\Filament\Resources\Content\PpNotifs\PpNotifResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPpNotifs extends ListRecords
{
    protected static string $resource = PpNotifResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
