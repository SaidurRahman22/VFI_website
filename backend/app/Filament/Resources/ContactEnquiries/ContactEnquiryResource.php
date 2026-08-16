<?php

namespace App\Filament\Resources\ContactEnquiries;

use App\Filament\Resources\ContactEnquiries\Pages\ListContactEnquiries;
use App\Filament\Resources\ContactEnquiries\Pages\ViewContactEnquiry;
use App\Filament\Resources\ContactEnquiries\Schemas\ContactEnquiryInfolist;
use App\Filament\Resources\ContactEnquiries\Tables\ContactEnquiriesTable;
use App\Models\ContactEnquiry;
use App\Support\StaffAbilities;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only staff inbox for public contact-form leads (Phase 2 §7.3).
 * No create/edit — leads only arrive via POST /api/contact.
 */
class ContactEnquiryResource extends Resource
{
    protected static ?string $model = ContactEnquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Contact Enquiries';

    protected static ?string $recordTitleAttribute = 'fname';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    /** Badge = number of new (unread) leads. */
    public static function getNavigationBadge(): ?string
    {
        $count = ContactEnquiry::where('status', 'new')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContactEnquiryInfolist::configure($schema);
    }

    /** Role gate: see App\Support\StaffAbilities for who holds this. */
    public static function canAccess(): bool
    {
        return StaffAbilities::current('enquiries.view');
    }

    public static function table(Table $table): Table
    {
        return ContactEnquiriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactEnquiries::route('/'),
            'view' => ViewContactEnquiry::route('/{record}'),
        ];
    }
}
