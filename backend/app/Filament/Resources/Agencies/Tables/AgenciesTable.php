<?php

namespace App\Filament\Resources\Agencies\Tables;

use App\Enums\AgencyStatus;
use App\Models\Partner\PartnerAgency;
use App\Services\AgencySuspensionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgenciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('legal_name')
            ->columns([
                TextColumn::make('legal_name')->label('Agency')->searchable()->sortable()->weight('bold'),
                TextColumn::make('country')->searchable()->sortable(),
                TextColumn::make('city')->toggleable(),
                TextColumn::make('status')->badge()->colors([
                    'success' => 'approved',
                    'warning' => ['pending_review', 'suspended'],
                    'danger' => ['rejected', 'closed'],
                ]),
                TextColumn::make('applications_all_count')->label('Applications')->badge()->color('gray'),
                TextColumn::make('approved_at')->dateTime('d M Y')->toggleable(),
                TextColumn::make('created_at')->label('Registered')->dateTime('d M Y')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(AgencyStatus::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => ucwords(str_replace('_', ' ', $c->value))])->all()
                ),
            ])
            ->recordActions([
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->color('warning')
                    ->visible(fn (PartnerAgency $r) => $r->status === AgencyStatus::Approved)
                    ->modalHeading('Suspend this agency?')
                    ->modalDescription('Everyone at this agency is signed out immediately and cannot sign in again until reinstated. Their data is kept.')
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->rows(3)
                            ->helperText('Recorded in the audit trail against your account.'),
                    ])
                    ->action(fn (PartnerAgency $record, array $data) => self::run(
                        fn () => app(AgencySuspensionService::class)->suspend($record, auth()->user(), $data['reason']),
                        'Agency suspended', 'Everyone at the agency has been signed out.'
                    )),

                Action::make('reinstate')
                    ->label('Reinstate')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->color('success')
                    ->visible(fn (PartnerAgency $r) => $r->status === AgencyStatus::Suspended)
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->rows(2),
                    ])
                    ->action(fn (PartnerAgency $record, array $data) => self::run(
                        fn () => app(AgencySuspensionService::class)->reinstate($record, auth()->user(), $data['reason']),
                        'Agency reinstated', 'They can sign in again.'
                    )),

                Action::make('close')
                    ->label('Close')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (PartnerAgency $r) => in_array($r->status, [AgencyStatus::Approved, AgencyStatus::Suspended], true))
                    ->modalHeading('Close this agency permanently?')
                    ->modalDescription('Closing is final — a closed agency cannot be reinstated and would have to re-register. Use Suspend if this may be temporary.')
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->rows(3),
                    ])
                    ->action(fn (PartnerAgency $record, array $data) => self::run(
                        fn () => app(AgencySuspensionService::class)->close($record, auth()->user(), $data['reason']),
                        'Agency closed', 'Access has been permanently withdrawn.'
                    )),
            ]);
    }

    /** Run a status change, turning a guard failure into a readable notice. */
    private static function run(callable $fn, string $title, string $body): void
    {
        try {
            $fn();
            Notification::make()->success()->title($title)->body($body)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not change status')->body($e->getMessage())->send();
        }
    }
}
