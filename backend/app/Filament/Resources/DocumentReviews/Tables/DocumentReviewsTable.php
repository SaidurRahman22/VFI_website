<?php

namespace App\Filament\Resources\DocumentReviews\Tables;

use App\Enums\DocumentStatus;
use App\Models\Student\StudentDocument;
use App\Services\DocumentReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('uploaded_at', 'desc')
            ->columns([
                TextColumn::make('uploaded_at')->label('Uploaded')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('student.email')->label('Student')->searchable()->description(
                    fn (StudentDocument $r) => trim(($r->student->first_name ?? '').' '.($r->student->last_name ?? '')) ?: null
                ),
                TextColumn::make('documentType.name')->label('Document')->searchable(),
                TextColumn::make('file.original_name')->label('File')->limit(28)->toggleable(),
                TextColumn::make('file.scan_status')->label('Scan')->badge()
                    ->colors(['success' => 'clean', 'warning' => 'pending', 'danger' => 'infected']),
                TextColumn::make('status')->badge()->colors([
                    'warning' => 'uploaded', 'success' => 'verified', 'danger' => 'rejected',
                ]),
                TextColumn::make('rejection_reason')->label('Reason')->limit(40)->toggleable()->wrap(),
                TextColumn::make('verified_at')->label('Decided')->dateTime('d M Y, H:i')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    DocumentStatus::Uploaded->value => 'Awaiting review',
                    DocumentStatus::Verified->value => 'Verified',
                    DocumentStatus::Rejected->value => 'Rejected',
                ])->default(DocumentStatus::Uploaded->value),
            ])
            ->recordActions([
                // Download goes through the same single-use, logged capability the
                // student side uses — staff never get a durable link to a passport.
                Action::make('download')
                    ->label('Open file')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (StudentDocument $r) => $r->file?->isReadable())
                    ->url(fn (StudentDocument $r) => route('staff.documents.download', $r), shouldOpenInNewTab: true),

                Action::make('verify')
                    ->label('Verify')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Verify this document?')
                    ->modalDescription('You are confirming this document is genuine and readable. The student is told, and the document is locked from replacement.')
                    ->visible(fn (StudentDocument $r) => $r->status === DocumentStatus::Uploaded)
                    ->action(function (StudentDocument $record) {
                        try {
                            app(DocumentReviewService::class)->verify($record, auth()->user());
                            Notification::make()->success()->title('Document verified')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not verify')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (StudentDocument $r) => $r->status === DocumentStatus::Uploaded)
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->rows(3)
                            ->helperText('The student reads this word for word — say exactly what to fix.'),
                    ])
                    ->action(function (StudentDocument $record, array $data) {
                        try {
                            app(DocumentReviewService::class)->reject($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Document rejected')->body('The student has been told what to replace.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not reject')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (StudentDocument $r) => $r->status === DocumentStatus::Verified)
                    ->schema([
                        Textarea::make('reason')->label('Why reopen?')->required()->rows(2)
                            ->helperText('Recorded in the audit trail. Unlocks the slot so the student can replace the file.'),
                    ])
                    ->action(function (StudentDocument $record, array $data) {
                        try {
                            app(DocumentReviewService::class)->reopen($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Reopened for review')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not reopen')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
