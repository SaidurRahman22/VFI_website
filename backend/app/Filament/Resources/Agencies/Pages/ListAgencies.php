<?php

namespace App\Filament\Resources\Agencies\Pages;

use App\Filament\Resources\Agencies\AgencyResource;
use Filament\Resources\Pages\ListRecords;

class ListAgencies extends ListRecords
{
    protected static string $resource = AgencyResource::class;

    /** No create action — an agency is minted by approving a partner application. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
