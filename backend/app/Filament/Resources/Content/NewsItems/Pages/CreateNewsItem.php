<?php

namespace App\Filament\Resources\Content\NewsItems\Pages;

use App\Filament\Resources\Content\NewsItems\NewsItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsItem extends CreateRecord
{
    protected static string $resource = NewsItemResource::class;
}
