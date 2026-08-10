<?php

namespace App\Filament\Resources\Content\Events\Pages;

use App\Filament\Resources\Content\Events\EventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;
}
