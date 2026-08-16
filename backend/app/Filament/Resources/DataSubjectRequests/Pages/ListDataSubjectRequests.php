<?php

namespace App\Filament\Resources\DataSubjectRequests\Pages;

use App\Filament\Resources\DataSubjectRequests\DataSubjectRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDataSubjectRequests extends ListRecords
{
    protected static string $resource = DataSubjectRequestResource::class;

    /**
     * No create action — a request row is written by the GDPR service that does
     * the work, never typed in by hand. Raising one is a toolbar action on the
     * table so the row and the work can never disagree.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
