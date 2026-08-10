<?php

namespace App\Filament\Resources\Content\PpNotifs\Pages;

use App\Filament\Resources\Content\PpNotifs\PpNotifResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPpNotif extends EditRecord
{
    protected static string $resource = PpNotifResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
