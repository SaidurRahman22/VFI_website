<?php

namespace App\Filament\Resources\UserRoles\Pages;

use App\Filament\Resources\UserRoles\UserRoleResource;
use Filament\Resources\Pages\ListRecords;

class ListUserRoles extends ListRecords
{
    protected static string $resource = UserRoleResource::class;

    /** No create action — accounts arrive by registration or invite, not here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
