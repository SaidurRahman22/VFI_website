<?php

namespace App\Filament\Resources\StudentLookup\Tables;

use App\Models\StaffAccessLog;
use App\Models\Student\Student;
use App\Services\StaffAccessService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class StudentLookupTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Nothing is listed until someone searches: an idle screen must not
            // be a browsable directory of every agency's students.
            ->modifyQueryUsing(fn ($query) => $query->when(
                blank(request()->input('tableSearch')),
                fn ($q) => $q->whereRaw('1 = 0'),
            ))
            ->emptyStateHeading('Search for a student')
            ->emptyStateDescription('Search by reference, email or name. Opening a record requires a reason and is recorded.')
            ->columns([
                TextColumn::make('student_ref')->label('Reference')->searchable()->copyable(),
                // masked in the list; the full address is behind the reason gate
                TextColumn::make('email')->label('Email')->searchable()
                    ->formatStateUsing(fn (?string $state) => self::maskEmail($state)),
                TextColumn::make('agency.legal_name')->label('Agency')->searchable()->placeholder('— self signup'),
                TextColumn::make('destination_country')->label('Destination')->toggleable(),
                TextColumn::make('created_at')->label('Registered')->date('d M Y')->sortable(),
            ])
            ->searchable()
            ->recordActions([
                Action::make('open')
                    ->label('Open record')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('warning')
                    ->modalHeading('Open a record from another agency')
                    ->modalDescription('This student belongs to a partner agency. Your name, the time and the reason below are recorded permanently, and are disclosable to that agency.')
                    ->schema([
                        Textarea::make('reason')->label('Why do you need to open this record?')->required()->rows(3)
                            ->helperText('Be specific — e.g. “complaint ref 4821, verifying the passport we were sent”. Minimum 10 characters.'),
                    ])
                    ->action(function (Student $record, array $data) {
                        try {
                            $student = app(StaffAccessService::class)
                                ->openStudent(auth()->user(), $record->id, $data['reason']);

                            Notification::make()->success()
                                ->title('Record opened')
                                ->body('This access has been logged against your account.')
                                ->send();

                            // held in the session only for the follow-up modal
                            session()->flash('lookup.student', $student->id);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not opened')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('accessHistory')
                    ->label('Who opened this')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Student $record) {
                        $rows = StaffAccessLog::where('subject_type', 'student')
                            ->where('subject_id', $record->id)
                            ->orderByDesc('created_at')->limit(50)->get();

                        if ($rows->isEmpty()) {
                            return new HtmlString('<p class="text-sm text-gray-500">Nobody has opened this record across tenancy.</p>');
                        }
                        $html = '<div class="space-y-2 text-sm">';
                        foreach ($rows as $r) {
                            $html .= '<div class="rounded-lg border p-2"><b>'.e($r->actor_email ?? 'unknown').'</b>'
                                .'<div class="text-xs text-gray-500">'.e(optional($r->created_at)->format('d M Y, H:i'))
                                .' · '.e($r->ip ?? '').'</div>'
                                .'<div class="mt-1">'.e($r->reason).'</div></div>';
                        }

                        return new HtmlString($html.'</div>');
                    }),
            ]);
    }

    /** Show enough of an address to recognise, not enough to harvest. */
    private static function maskEmail(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '—';
        }
        [$local, $domain] = explode('@', $email, 2);
        $head = mb_substr($local, 0, 2);

        return $head.str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
