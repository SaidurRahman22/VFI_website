<?php

namespace App\Filament\Resources\StaffApplications\Tables;

use App\Enums\ApplicationStatus;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationNote;
use App\Services\ApplicationReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class StaffApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('student.email')->label('Student')->searchable()->description(
                    fn (Application $r) => trim(($r->student->first_name ?? '').' '.($r->student->last_name ?? '')) ?: null
                ),
                TextColumn::make('agency.legal_name')->label('Agency')->searchable()->toggleable(),
                TextColumn::make('status')->badge()->colors([
                    'gray' => 'submitted', 'info' => 'review', 'success' => 'offer',
                    'warning' => ['conditional', 'pending_from_partner', 'deferral', 'payment'],
                    'danger' => ['visa_rejected', 'non_enrolment'],
                ]),
                TextColumn::make('ack_no')->label('Ack no.')->toggleable()->searchable(),
                TextColumn::make('deadline_at')->label('Deadline')->date('d M Y')->sortable()->toggleable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('notes_count')->label('Notes')
                    ->state(fn (Application $r) => ApplicationNote::where('application_id', $r->id)->count())
                    ->badge()->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ApplicationStatus::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => ucwords(str_replace('_', ' ', $c->value))])->all()
                ),
            ])
            ->recordActions([
                // ---- move the case on ----
                Action::make('advance')
                    ->label('Move')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->color('primary')
                    ->visible(fn (Application $r) => app(ApplicationReviewService::class)->allowedNextStatuses($r->status) !== [])
                    ->schema(fn (Application $record) => [
                        Select::make('to')
                            ->label('Move to')
                            ->required()
                            // only legal next steps are even offered
                            ->options(collect(app(ApplicationReviewService::class)->allowedNextStatuses($record->status))
                                ->mapWithKeys(fn (ApplicationStatus $s) => [$s->value => ucwords(str_replace('_', ' ', $s->value))])->all()),
                        Textarea::make('reason')->label('Reason / note to the agency')->rows(3)
                            ->helperText('Required when a case is stalled, deferred, rejected or closed. The agency sees this.'),
                    ])
                    ->action(function (Application $record, array $data) {
                        try {
                            app(ApplicationReviewService::class)->transition(
                                $record,
                                ApplicationStatus::from($data['to']),
                                auth()->user(),
                                $data['reason'] ?? null,
                            );
                            Notification::make()->success()->title('Application updated')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not update')->body($e->getMessage())->send();
                        }
                    }),

                // ---- staff-internal notes ----
                Action::make('addNote')
                    ->label('Add note')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->schema([
                        Textarea::make('body')->label('Internal note')->required()->rows(4)
                            ->helperText('Staff-only. Never shown to the student or the partner agency. Notes cannot be edited — add another to correct one.'),
                    ])
                    ->action(function (Application $record, array $data) {
                        try {
                            app(ApplicationReviewService::class)->addNote($record, auth()->user(), $data['body']);
                            Notification::make()->success()->title('Note added')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not add note')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('viewNotes')
                    ->label('Notes')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Application $record) {
                        $notes = ApplicationNote::where('application_id', $record->id)
                            ->orderByDesc('created_at')->limit(50)->get();
                        if ($notes->isEmpty()) {
                            return new HtmlString('<p class="text-sm text-gray-500">No internal notes yet.</p>');
                        }
                        $html = '<div class="space-y-3 text-sm">';
                        foreach ($notes as $n) {
                            $html .= '<div class="rounded-lg border p-3">'
                                .'<div class="text-xs text-gray-500">'.e($n->author_name ?? 'Staff').' · '
                                .e(optional($n->created_at)->format('d M Y, H:i')).'</div>'
                                .'<div class="mt-1 whitespace-pre-line">'.e($n->body).'</div></div>';
                        }

                        return new HtmlString($html.'</div>');
                    }),
            ]);
    }
}
