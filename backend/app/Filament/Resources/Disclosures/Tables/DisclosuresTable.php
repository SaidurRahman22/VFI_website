<?php

namespace App\Filament\Resources\Disclosures\Tables;

use App\Models\DocumentDisclosure;
use App\Services\Gdpr\DisclosureService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DisclosuresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('disclosed_at', 'desc')
            ->columns([
                TextColumn::make('disclosed_at')->label('Disclosed')->dateTime('d M Y, H:i')->sortable(),

                TextColumn::make('student.email')->label('Student')->searchable()->placeholder('—'),

                TextColumn::make('file.original_name')->label('Document')->limit(28)
                    ->placeholder('— no single file')->toggleable(),

                TextColumn::make('recipient_name')->label('Recipient')->searchable()->wrap(),

                TextColumn::make('recipient_type')->label('Recipient type')->badge()->colors([
                    'info' => 'university',
                    'warning' => 'lender',
                    'danger' => 'government',
                    'gray' => 'other',
                ]),

                TextColumn::make('lawful_basis')->label('Lawful basis')->badge()
                    ->formatStateUsing(fn (?string $state) => self::humanise($state))
                    ->colors([
                        'success' => 'consent',
                        'info' => 'contract',
                        'warning' => 'legal_obligation',
                        'gray' => 'legitimate_interest',
                    ]),

                TextColumn::make('disclosedBy.email')->label('Recorded by')
                    ->placeholder('— system')->searchable()->toggleable(),

                TextColumn::make('note')->label('Note')->limit(48)->wrap()
                    ->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('recipient_type')->label('Recipient type')
                    ->options(self::options(DocumentDisclosure::RECIPIENT_TYPES)),
                SelectFilter::make('lawful_basis')->label('Lawful basis')
                    ->options(self::options(DocumentDisclosure::LAWFUL_BASES)),
            ])
            ->toolbarActions([
                Action::make('recordDisclosure')
                    ->label('Record a disclosure')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('primary')
                    ->modalHeading('Record an onward disclosure')
                    ->modalDescription('Use this whenever a document or personal data leaves VFI — sent to a university, a lender, or handed to a government body. The register is what the student is shown if they ask who has their file.')
                    ->modalSubmitActionLabel('Record it')
                    ->schema([
                        TextInput::make('document_file_id')->label('Document file ID')
                            ->numeric()->minValue(1)->integer()
                            ->helperText('Leave blank if what was disclosed was not one specific file.'),
                        TextInput::make('student_id')->label('Student ID')
                            ->numeric()->minValue(1)->integer()
                            ->helperText('Leave blank only if the disclosure is not about one student.'),
                        TextInput::make('recipient_name')->label('Who received it')->required()
                            ->maxLength(190)
                            ->helperText('Name the actual organisation, e.g. “University of Leeds — admissions”.'),
                        Select::make('recipient_type')->label('Recipient type')->required()
                            ->options(self::options(DocumentDisclosure::RECIPIENT_TYPES)),
                        Select::make('lawful_basis')->label('Lawful basis')->required()
                            ->options(self::options(DocumentDisclosure::LAWFUL_BASES)),
                        Textarea::make('note')->label('Note')->rows(3)
                            ->helperText('What was sent and why, in words a student would understand.'),
                        DateTimePicker::make('disclosed_at')->label('When it was disclosed')->required()
                            ->seconds(false)->default(now())
                            ->helperText('Backdate this if you are recording something that already went out.'),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(DisclosureService::class)->record($data, auth()->user());
                            Notification::make()->success()->title('Disclosure recorded')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /**
     * Turn a list of stored values into value => label options.
     *
     * Built from the model constants rather than a second hand-written list, so
     * adding a recipient type in one place cannot leave the admin panel behind.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function options(array $values): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = self::humanise($value);
        }

        return $options;
    }

    private static function humanise(?string $value): string
    {
        return $value === null || $value === '' ? '—' : ucfirst(str_replace('_', ' ', $value));
    }
}
