<?php

namespace App\Filament\Resources\Disclosures\Pages;

use App\Filament\Resources\Disclosures\DisclosureResource;
use Filament\Resources\Pages\ListRecords;

class ListDisclosures extends ListRecords
{
    protected static string $resource = DisclosureResource::class;

    /**
     * No create action here — recording a disclosure goes through the toolbar
     * action on the table, which routes it via DisclosureService so the row is
     * validated and audited rather than typed straight into the register.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
