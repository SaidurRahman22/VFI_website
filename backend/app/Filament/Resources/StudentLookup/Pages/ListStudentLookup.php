<?php

namespace App\Filament\Resources\StudentLookup\Pages;

use App\Filament\Resources\StudentLookup\StudentLookupResource;
use Filament\Resources\Pages\ListRecords;

class ListStudentLookup extends ListRecords
{
    protected static string $resource = StudentLookupResource::class;

    /** No create action — students arrive by registration or a partner's modal. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
