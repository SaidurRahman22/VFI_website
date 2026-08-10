<?php

namespace App\Filament\Resources\Content\PpQuicklinks\Pages;

use App\Filament\Resources\Content\PpQuicklinks\PpQuicklinkResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPpQuicklink extends EditRecord
{
    protected static string $resource = PpQuicklinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
