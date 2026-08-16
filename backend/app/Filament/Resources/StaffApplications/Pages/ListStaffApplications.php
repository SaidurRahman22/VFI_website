<?php

namespace App\Filament\Resources\StaffApplications\Pages;

use App\Filament\Resources\StaffApplications\StaffApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListStaffApplications extends ListRecords
{
    protected static string $resource = StaffApplicationResource::class;

    /** No create action — applications only ever originate from a partner. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
