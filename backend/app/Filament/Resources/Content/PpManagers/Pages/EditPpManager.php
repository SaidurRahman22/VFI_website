<?php

namespace App\Filament\Resources\Content\PpManagers\Pages;

use App\Filament\Resources\Content\PpManagers\PpManagerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPpManager extends EditRecord
{
    protected static string $resource = PpManagerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
