<?php

namespace App\Filament\Resources\Content\PpEmails\Pages;

use App\Filament\Resources\Content\PpEmails\PpEmailResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPpEmails extends ListRecords
{
    protected static string $resource = PpEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
