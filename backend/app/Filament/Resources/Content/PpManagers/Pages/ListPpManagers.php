<?php

namespace App\Filament\Resources\Content\PpManagers\Pages;

use App\Filament\Resources\Content\PpManagers\PpManagerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPpManagers extends ListRecords
{
    protected static string $resource = PpManagerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
