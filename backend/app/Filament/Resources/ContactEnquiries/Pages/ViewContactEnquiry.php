<?php

namespace App\Filament\Resources\ContactEnquiries\Pages;

use App\Filament\Resources\ContactEnquiries\ContactEnquiryResource;
use App\Models\ContactEnquiry;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewContactEnquiry extends ViewRecord
{
    protected static string $resource = ContactEnquiryResource::class;

    protected function getHeaderActions(): array
    {
        // read-only inbox: no edit; just quick status toggles
        return [
            Action::make('markRead')
                ->label('Mark as read')
                ->icon('heroicon-o-check')
                ->visible(fn (ContactEnquiry $record) => $record->status === 'new')
                ->action(fn (ContactEnquiry $record) => $record->update(['status' => 'read'])),
            Action::make('archive')
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->visible(fn (ContactEnquiry $record) => $record->status !== 'archived')
                ->action(fn (ContactEnquiry $record) => $record->update(['status' => 'archived'])),
        ];
    }
}
