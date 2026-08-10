<?php

namespace App\Filament\Resources\Content\PpDocs\Pages;

use App\Filament\Resources\Content\PpDocs\PpDocResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPpDoc extends EditRecord
{
    protected static string $resource = PpDocResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
