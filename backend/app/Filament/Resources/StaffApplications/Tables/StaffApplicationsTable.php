<?php

namespace App\Filament\Resources\StaffApplications\Tables;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ScanStatus;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationNote;
use App\Models\Student\DocumentType;
use App\Models\Student\StudentDocument;
use App\Services\ApplicationReadiness;
use App\Services\ApplicationReviewService;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use WeakMap;

class StaffApplicationsTable
{
    /** The pack an application is judged on; the visa pack belongs to a later stage. */
    private const PACK = 'application';

    /** Per-render memo for the readiness badge — see readiness(). */
    private static ?WeakMap $readiness = null;

    public static function configure(Table $table): Table
    {
        // Resolved ONCE for the whole page and closed over by the row closures.
        // ApplicationReadiness memoises document_types on the instance it is asked
        // on, and app() hands out a fresh instance every call — so resolving it
        // inside the closure would turn static reference data into a query per
        // visible row.
        $readiness = app(ApplicationReadiness::class);

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
                // Is there anything to check yet? A case with no paperwork cannot be
                // processed, and staff could not see that from this queue at all.
                // Three closures, ONE readiness summary per row: the result is
                // memoised per record, so the badge costs a single service call
                // rather than one for each of state, colour and tooltip.
                TextColumn::make('documents_readiness')
                    ->label('Documents')
                    ->badge()
                    ->state(fn (Application $r) => self::readiness($readiness, $r)['label'])
                    ->color(fn (Application $r) => self::readiness($readiness, $r)['color'])
                    ->tooltip(fn (Application $r) => self::readiness($readiness, $r)['tooltip']),
                TextColumn::make('ack_no')->label('Ack no.')->toggleable()->searchable(),
                TextColumn::make('deadline_at')->label('Deadline')->date('d M Y')->sortable()->toggleable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('d M Y, H:i')->sortable(),
                // counted in the base query (withCount) — a per-row count here
                // would be one extra query per visible row
                TextColumn::make('notes_count')->label('Notes')->badge()->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ApplicationStatus::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => ucwords(str_replace('_', ' ', $c->value))])->all()
                ),
            ])
            ->recordActions([
                // ---- check the paperwork ----
                // First in the row because that is the order of the job: read the
                // checklist, then move the case. Read-only — verifying and rejecting
                // stay in the Document review queue, which is the one screen that
                // owns those decisions.
                Action::make('documents')
                    ->label('Documents')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalHeading(fn (Application $record) => 'Documents — '.($record->student->email ?? 'unknown student'))
                    ->modalDescription('Read-only. Every file opened from here is written to the document access log.')
                    ->modalContent(fn (Application $record) => new HtmlString(self::checklistHtml($record))),

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

    // ---- document readiness ----

    /**
     * The badge summary for one row.
     *
     * Memoised on the record OBJECT, not on its id: a static id-keyed cache
     * would outlive the request under a persistent worker and hand the next
     * render a stale count. A WeakMap entry dies with the record it belongs to.
     */
    private static function readiness(ApplicationReadiness $service, Application $record): array
    {
        self::$readiness ??= new WeakMap;

        return self::$readiness[$record] ??= self::summarise($service, $record);
    }

    /**
     * ApplicationReadiness answers in document-type KEYS, not counts, so the two
     * numbers on the badge are measured the same way it measures readiness: over
     * the REQUIRED set only. Counting the whole pack instead would let an
     * optional type drag a perfectly processable case down to "5/6".
     */
    private static function summarise(ApplicationReadiness $service, Application $record): array
    {
        $student = $record->student;

        if (! $student) {
            // Nothing to read a checklist off. The queue still has to render:
            // a case in this state is precisely what staff need to see.
            return ['label' => '—', 'color' => 'gray', 'tooltip' => 'No student on this case.'];
        }

        $summary = $service->for($student, self::PACK);

        $required = count($summary['required']);
        $supplied = count(array_intersect($summary['required'], $summary['present']));
        $rejected = $summary['rejected'] !== [];

        return [
            'label' => $supplied.'/'.$required,
            'color' => match (true) {
                $rejected => 'danger',
                $summary['ready'] => 'success',
                default => 'warning',
            },
            'tooltip' => match (true) {
                $rejected => 'A document was rejected — open Documents for the reason.',
                $summary['complete'] => 'Every document is in and verified.',
                $summary['ready'] => 'Every document is in; some are still to be verified.',
                default => ($required - $supplied).' still to come.',
            },
        ];
    }

    // ---- the modal ----

    /**
     * Every required type for this case, present or not.
     *
     * Two queries per open (the types, then this student's rows with their
     * files), matched in PHP: a query per type would be a dozen round-trips
     * each time a case is opened. Built from the document rows themselves
     * rather than from the badge summary, so the verdict at the top and the
     * list underneath it can never disagree.
     */
    private static function checklistHtml(Application $record): string
    {
        $student = $record->student;

        if (! $student) {
            return '<p class="text-sm text-gray-500">This case has no student record.</p>';
        }

        $types = DocumentType::where('pack', self::PACK)->orderBy('position')->get();
        $byType = StudentDocument::with('file')
            ->where('student_id', $student->id)->get()->keyBy('document_type_id');

        $required = 0;
        $supplied = 0;
        $missing = [];
        $rejected = [];
        $rows = '';

        foreach ($types as $type) {
            $doc = $byType->get($type->id);
            $status = $doc?->status ?? DocumentStatus::Missing;

            // Counted the way ApplicationReadiness counts, so the badge on the row
            // behind this modal and the verdict above cannot contradict each other:
            // a destination-dependent type is listed but never gates the case.
            $isRequired = ! $type->destination_dependent;
            $required += $isRequired ? 1 : 0;

            if ($status->isPresent()) {
                $supplied += $isRequired ? 1 : 0;
            } elseif ($status === DocumentStatus::Rejected) {
                $rejected[] = $type->name;
            } elseif ($isRequired) {
                $missing[] = $type->name;
            }

            $rows .= self::rowHtml($type, $doc);
        }

        return '<div class="space-y-3 text-sm">'
            .self::verdictHtml($supplied, $required, $missing, $rejected)
            .$rows
            .'</div>';
    }

    /**
     * The leading line. A case officer should not have to compare two lists by
     * eye to work out whether the case can be processed, so the verdict and
     * whatever is holding it up are stated first, in words.
     */
    private static function verdictHtml(int $supplied, int $required, array $missing, array $rejected): string
    {
        $headline = ($missing === [] && $rejected === [])
            ? 'All '.$required.' application documents are in — check them below.'
            : 'NOT READY — '.$supplied.' of '.$required.' application documents supplied.';

        $detail = [];
        if ($missing !== []) {
            $detail[] = 'Still missing: '.implode(', ', $missing).'.';
        }
        if ($rejected !== []) {
            $detail[] = 'Rejected and not yet replaced: '.implode(', ', $rejected).'.';
        }

        return '<div class="rounded-lg border p-3">'
            .'<div class="font-medium">'.e($headline).'</div>'
            .($detail === [] ? '' : '<div class="mt-1 text-gray-500">'.e(implode(' ', $detail)).'</div>')
            .'</div>';
    }

    /** One checklist line: state, file, scan status, rejection reason, download. */
    private static function rowHtml(DocumentType $type, ?StudentDocument $doc): string
    {
        $status = $doc?->status ?? DocumentStatus::Missing;
        $file = $doc?->file;
        $name = $type->name.($type->destination_dependent ? ' (only some destinations)' : '');

        $state = match ($status) {
            DocumentStatus::Verified => 'Verified'.self::on($doc?->verified_at),
            DocumentStatus::Rejected => 'Rejected'.self::on($doc?->verified_at),
            DocumentStatus::Uploaded => 'Uploaded'.self::on($doc?->uploaded_at).' — awaiting review',
            default => 'Not supplied',
        };

        $html = '<div class="rounded-lg border p-3">'
            .'<div class="font-medium">'.e($name).'</div>'
            .'<div class="mt-1 text-xs text-gray-500">'.e($state).'</div>';

        if ($file) {
            $html .= '<div class="mt-1 text-xs text-gray-500">'
                .e($file->original_name).' · '.e(self::size($file->size))
                .' · scan: '.e($file->scan_status->value).'</div>';

            // The one existing staff route, which mints a single-use capability and
            // logs the open. A file that is not clean gets no link at all: pending
            // has nothing readable behind it, infected was dropped on arrival.
            $html .= $file->isReadable()
                ? '<div class="mt-2"><a class="underline" target="_blank" rel="noopener noreferrer" href="'
                    .e(route('staff.documents.download', $doc->id)).'">Open file</a></div>'
                : '<div class="mt-1 text-xs text-gray-500">'
                    .($file->scan_status === ScanStatus::Infected
                        ? 'Quarantined by the virus scan — the file was not kept.'
                        : 'Held until the virus scan clears; it cannot be opened yet.')
                    .'</div>';
        } elseif ($status !== DocumentStatus::Missing) {
            $html .= '<div class="mt-1 text-xs text-gray-500">No file on record.</div>';
        }

        if (filled($doc?->rejection_reason)) {
            // Verbatim: this is the wording the agency and the student were given,
            // and a paraphrase here sends them chasing the wrong thing.
            $label = $status === DocumentStatus::Rejected ? 'Rejected: ' : 'Earlier rejection: ';
            $html .= '<div class="mt-2 whitespace-pre-line">'.e($label.$doc->rejection_reason).'</div>';
        }

        return $html.'</div>';
    }

    private static function on(?DateTimeInterface $at): string
    {
        return $at ? ' on '.$at->format('d M Y') : '';
    }

    /** Rounded for a human — the exact byte count is not what a case officer needs. */
    private static function size(?int $bytes): string
    {
        if (! $bytes) {
            return 'size unknown';
        }

        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
