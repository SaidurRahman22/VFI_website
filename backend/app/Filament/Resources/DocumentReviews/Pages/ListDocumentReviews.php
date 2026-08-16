<?php

namespace App\Filament\Resources\DocumentReviews\Pages;

use App\Filament\Resources\DocumentReviews\DocumentReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListDocumentReviews extends ListRecords
{
    protected static string $resource = DocumentReviewResource::class;

    /** No create action — documents only ever arrive from a student upload. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
