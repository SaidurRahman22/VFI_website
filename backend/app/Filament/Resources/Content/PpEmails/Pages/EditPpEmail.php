<?php

namespace App\Filament\Resources\Content\PpEmails\Pages;

use App\Filament\Resources\Content\PpEmails\PpEmailResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPpEmail extends EditRecord
{
    protected static string $resource = PpEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
