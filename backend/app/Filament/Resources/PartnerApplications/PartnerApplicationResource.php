<?php

namespace App\Filament\Resources\PartnerApplications;

use App\Enums\ApplicationReviewStatus;
use App\Enums\Role;
use App\Filament\Resources\PartnerApplications\Pages\ListPartnerApplications;
use App\Models\Partner\PartnerApplication;
use App\Services\PartnerReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Phase 6F — staff review of partner applications. Approve mints the tenant
 * (via PartnerReview); reject / request-more-info never do. Visible only to the
 * super admin and staff_partner_ops.
 */
class PartnerApplicationResource extends Resource
{
    protected static ?string $model = PartnerApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Partner applications';

    protected static ?string $recordTitleAttribute = 'agency_name';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u && ($u->isSuperAdmin() || $u->hasRole(Role::StaffPartnerOps));
    }

    public static function canCreate(): bool
    {
        return false;   // applications arrive via the public wizard
    }

    public static function table(Table $table): Table
    {
        $actionable = fn (PartnerApplication $r) => in_array(
            $r->review_status,
            [ApplicationReviewStatus::Pending, ApplicationReviewStatus::MoreInfo],
            true,
        );

        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('agency_name')->searchable()->sortable(),
                TextColumn::make('country'),
                TextColumn::make('contact_person')->label('Contact'),
                TextColumn::make('work_email')->searchable()->copyable(),
                TextColumn::make('review_status')->badge()->colors([
                    'warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected', 'info' => 'more_info',
                ]),
                TextColumn::make('review_notes')->limit(40)->toggleable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->modalHeading('Approve this agency?')
                    ->modalDescription('This creates a live tenant and an owner seat, and lets the agency sign in.')
                    ->visible($actionable)
                    ->action(function (PartnerApplication $record) {
                        app(PartnerReview::class)->approve($record, auth()->user());
                        Notification::make()->success()->title('Agency approved and tenant created.')->send();
                    }),
                Action::make('requestMoreInfo')
                    ->label('More info')->color('warning')->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->schema([Textarea::make('notes')->label('What do you need from the applicant?')->required()->maxLength(2000)])
                    ->visible($actionable)
                    ->action(function (array $data, PartnerApplication $record) {
                        app(PartnerReview::class)->requestMoreInfo($record, auth()->user(), $data['notes']);
                        Notification::make()->success()->title('Requested more information.')->send();
                    }),
                Action::make('reject')
                    ->color('danger')->icon(Heroicon::OutlinedXCircle)
                    ->schema([Textarea::make('reason')->label('Reason (sent to the applicant)')->required()->maxLength(2000)])
                    ->visible($actionable)
                    ->action(function (array $data, PartnerApplication $record) {
                        app(PartnerReview::class)->reject($record, auth()->user(), $data['reason']);
                        Notification::make()->success()->title('Application rejected.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPartnerApplications::route('/')];
    }
}
