<?php

namespace App\Filament\Resources\PartnerApplications\Pages;

use App\Filament\Resources\PartnerApplications\PartnerApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnerApplications extends ListRecords
{
    protected static string $resource = PartnerApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // applications arrive via the public wizard — no create
    }
}
