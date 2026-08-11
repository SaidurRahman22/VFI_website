<?php

namespace App\Filament\Resources\Universities\Pages;

use App\Filament\Resources\Universities\UniversityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUniversity extends CreateRecord
{
    protected static string $resource = UniversityResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Manually-created universities are staff-owned, not from an ingest feed.
        $data['source'] = $data['source'] ?? 'admin';

        return $data;
    }
}
