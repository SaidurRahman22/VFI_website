<?php

namespace App\Filament\Resources\Content\PpUpdates\Pages;

use App\Filament\Resources\Content\PpUpdates\PpUpdateResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPpUpdate extends EditRecord
{
    protected static string $resource = PpUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
