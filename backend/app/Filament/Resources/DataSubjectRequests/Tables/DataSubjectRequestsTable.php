<?php

namespace App\Filament\Resources\DataSubjectRequests\Tables;

use App\Models\DataSubjectRequest;
use App\Services\Gdpr\DataSubjectErasureService;
use App\Services\Gdpr\DataSubjectExportService;
use App\Support\StepUp;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class DataSubjectRequestsTable
{
    /** The two kinds of person this platform holds a file on. */
    private const SUBJECT_TYPES = ['student' => 'Student', 'user' => 'Staff / partner user'];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Raised')->dateTime('d M Y, H:i')->sortable(),

                TextColumn::make('type')->label('Type')->badge()->colors([
                    'info' => DataSubjectRequest::TYPE_EXPORT,
                    'danger' => DataSubjectRequest::TYPE_ERASURE,
                ]),

                // one column, because "student" on its own identifies nobody
                TextColumn::make('subject_type')->label('Subject')
                    ->formatStateUsing(fn (?string $state, DataSubjectRequest $r) => ($state ?: '—').' #'.$r->subject_id)
                    ->sortable(),

                TextColumn::make('subject_email')->label('Subject email')->searchable()
                    ->placeholder('—')->toggleable(),

                TextColumn::make('status')->badge()->colors([
                    'success' => DataSubjectRequest::STATUS_COMPLETED,
                    'warning' => DataSubjectRequest::STATUS_BLOCKED,
                    'danger' => DataSubjectRequest::STATUS_FAILED,
                    'gray' => DataSubjectRequest::STATUS_PENDING,
                ]),

                // why a request was blocked or what was held back is the part an
                // auditor actually reads
                TextColumn::make('outcome')->label('Outcome')->limit(48)->wrap()
                    ->placeholder('—')->toggleable(),

                TextColumn::make('requestedBy.email')->label('Raised by')
                    ->placeholder('— system')->searchable()->toggleable(),

                TextColumn::make('completed_at')->label('Completed')->dateTime('d M Y, H:i')
                    ->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    DataSubjectRequest::TYPE_EXPORT => 'Export',
                    DataSubjectRequest::TYPE_ERASURE => 'Erasure',
                ]),
                SelectFilter::make('status')->options([
                    DataSubjectRequest::STATUS_PENDING => 'Pending',
                    DataSubjectRequest::STATUS_COMPLETED => 'Completed',
                    DataSubjectRequest::STATUS_BLOCKED => 'Blocked (legal hold)',
                    DataSubjectRequest::STATUS_FAILED => 'Failed',
                ]),
            ])
            ->toolbarActions([
                // Raising a request is a toolbar action rather than a create page
                // so the register row and the work that produced it are written by
                // the same service call and can never disagree.
                Action::make('newExport')
                    ->label('New export')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('primary')
                    ->modalHeading('Raise a subject access export')
                    ->modalDescription('Builds a bundle of everything held about this person. The bundle stays on the private disk until someone downloads it, and both steps are recorded.')
                    ->modalSubmitActionLabel('Build export')
                    ->schema([
                        Select::make('subject_type')->label('Subject type')->required()
                            ->options(self::SUBJECT_TYPES)
                            ->default('student'),
                        TextInput::make('subject_id')->label('Subject ID')->required()
                            ->numeric()->minValue(1)->integer()
                            ->helperText('The numeric id of the student or user record.'),
                        Textarea::make('reason')->label('Why is this being raised?')->rows(3)
                            ->helperText('e.g. “SAR received by email 12 Aug, ref 4821”. Kept on the register.'),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(DataSubjectExportService::class)->export(
                                (string) $data['subject_type'],
                                (int) $data['subject_id'],
                                auth()->user(),
                                $data['reason'] ?? null,
                            );
                            Notification::make()->success()
                                ->title('Export built')
                                ->body('Use Download on the new row to fetch the bundle.')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Export not built')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('newErasure')
                    ->label('New erasure')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Erase a data subject')
                    ->modalDescription('This permanently destroys personal data and CANNOT be undone. Records under a legal hold are refused, not silently skipped. Preview an erasure first if you are unsure.')
                    ->modalSubmitActionLabel('Erase permanently')
                    // Destructive and irreversible, so the actor re-proves it is
                    // really them at the moment of the write — exactly as role
                    // changes do. An open session on an unlocked laptop is not
                    // authority to delete somebody's record.
                    ->schema([
                        // Student only. A staff or partner user is erased by
                        // closing the account, not by destroying the audit trail
                        // that account is part of — offering the option here
                        // would only ever produce a refusal.
                        Select::make('subject_type')->label('Subject type')->required()
                            ->options(['student' => self::SUBJECT_TYPES['student']])
                            ->default('student')
                            ->helperText('Only students can be erased. Staff and partner accounts are closed instead.'),
                        TextInput::make('subject_id')->label('Subject ID')->required()
                            ->numeric()->minValue(1)->integer(),
                        Textarea::make('reason')->label('Why is this being erased?')->required()->rows(3)
                            ->helperText('Be specific — this is the justification an auditor reads back.'),
                        TextInput::make('code')->label('Your 6-digit authenticator code')->required()
                            ->helperText('Re-confirms it is really you before anything is destroyed.'),
                    ])
                    ->action(function (array $data) {
                        try {
                            $actor = auth()->user();
                            StepUp::assert($actor, $data['code'] ?? null, 'gdpr_erasure');
                            app(DataSubjectErasureService::class)->erase(
                                (string) $data['subject_type'],
                                (int) $data['subject_id'],
                                $actor,
                                $data['reason'],
                            );
                            Notification::make()->success()
                                ->title('Erasure run')
                                ->body('Check the new row for what was erased and what was held back.')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not erased')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->recordActions([
                // Dry run: shows what WOULD go and what a legal hold would keep,
                // before anyone commits to the irreversible version.
                Action::make('previewErasure')
                    ->label('Preview erasure')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (DataSubjectRequest $r) => $r->type === DataSubjectRequest::TYPE_ERASURE)
                    ->modalHeading('What an erasure would do')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (DataSubjectRequest $record) {
                        try {
                            $preview = app(DataSubjectErasureService::class)
                                ->preview((string) $record->subject_type, (int) $record->subject_id);
                        } catch (\Throwable $e) {
                            // A modal must never 500 — a failed preview is
                            // information, not a broken screen.
                            return new HtmlString('<p class="text-sm text-danger-600">'.e($e->getMessage()).'</p>');
                        }

                        if ($preview === []) {
                            return new HtmlString('<p class="text-sm text-gray-500">Nothing to erase for this subject.</p>');
                        }

                        return new HtmlString('<div class="space-y-2 text-sm">'.self::renderRows($preview).'</div>');
                    }),

                // Not a durable link: the route re-checks the record is a
                // completed export and logs the fetch before streaming bytes.
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('primary')
                    ->visible(fn (DataSubjectRequest $r) => $r->isDownloadable())
                    ->url(fn (DataSubjectRequest $r) => route('admin.gdpr.export.download', $r), shouldOpenInNewTab: true),
            ]);
    }

    /**
     * Render the preview array as escaped, read-only key/value rows.
     *
     * The shape is the service's to decide, so this walks whatever it gets
     * rather than assuming a fixed set of keys. Everything is passed through
     * e() — the values are somebody's personal data, not markup.
     *
     * @param  array<mixed>  $rows
     */
    private static function renderRows(array $rows, int $depth = 0): string
    {
        // Guard against a pathologically deep structure rendering forever.
        if ($depth > 3) {
            return '';
        }

        $html = '';
        foreach ($rows as $key => $value) {
            $label = is_int($key) ? '#'.($key + 1) : str_replace('_', ' ', (string) $key);

            if (is_array($value)) {
                $inner = $value === []
                    ? '<span class="text-gray-500">nothing</span>'
                    : self::renderRows($value, $depth + 1);
                $html .= '<div class="rounded-lg border p-2"><b>'.e($label).'</b>'
                    .'<div class="mt-1 space-y-1 ps-3">'.$inner.'</div></div>';

                continue;
            }

            $html .= '<div class="rounded-lg border p-2"><b>'.e($label).'</b>: '
                .e(self::scalarToText($value)).'</div>';
        }

        return $html;
    }

    private static function scalarToText(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            $value === null => '—',
            default => (string) $value,
        };
    }
}
