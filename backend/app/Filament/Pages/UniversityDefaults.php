<?php

namespace App\Filament\Pages;

use App\Models\SiteContent;
use App\Services\ImageOptimiser;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Phase 8+ — the copy the university detail template falls back to when a
 * university has no editorial content of its own (intake season cards, the
 * cost intro + footnote, the default FAQ set, the scholarship note). Stored in
 * the `universityPage` SiteContent singleton and served to the public page, so
 * none of this text is hardcoded in the frontend.
 */
class UniversityDefaults extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'University page defaults';

    protected static ?string $title = 'University page defaults';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.university-defaults';

    public ?array $data = [];

    public const KEY = 'universityPage';

    public function mount(): void
    {
        $this->form->fill(SiteContent::value(self::KEY, []) ?: []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Intake season cards')
                    ->description('Used to build the Intakes cards when a university has no intake cards of its own.')
                    ->schema([
                        Repeater::make('seasons')->label('Seasons')->collapsed()->columns(2)
                            ->itemLabel(fn (array $state): ?string => $state['key'] ?? null)
                            ->schema([
                                TextInput::make('key')->label('Season key')->placeholder('fall')->required()
                                    ->helperText('One of: fall, spring, summer, winter — must match the catalogue.'),
                                TextInput::make('month')->placeholder('September'),
                                Textarea::make('note')->rows(2)->columnSpanFull(),
                                FileUpload::make('image')->label('Card image')->image()
                                    ->disk('public')->directory('media/universities/intakes')->visibility('public')
                                    ->maxSize(3072)->columnSpanFull()
                                    ->saveUploadedFileUsing(ImageOptimiser::storeOptimised(1200)),
                            ])->columnSpanFull(),
                        Textarea::make('intake_footnote')->label('Footnote under the intake cards')->rows(2)->columnSpanFull(),
                    ]),

                Section::make('Cost to study')->schema([
                    Textarea::make('cost_intro')->label('Intro paragraph')->rows(4)->columnSpanFull()
                        ->helperText('Use {university} where the university name should appear.'),
                    Textarea::make('cost_footnote')->label('Footnote under the table')->rows(3)->columnSpanFull(),
                ]),

                Section::make('Scholarships')->schema([
                    Textarea::make('scholarship_note')->label('Note when a university has no scholarship cards')->rows(2)->columnSpanFull()
                        ->helperText('Use {university} for the university name.'),
                ]),

                Section::make('Default FAQs')
                    ->description('Shown when a university has no FAQs of its own.')
                    ->schema([
                        Repeater::make('faqs')->label('FAQs')->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['q'] ?? null)
                            ->schema([
                                TextInput::make('q')->label('Question')->required()->columnSpanFull(),
                                Textarea::make('a')->label('Answer')->rows(2)->columnSpanFull(),
                            ])->columnSpanFull(),
                    ]),

                Section::make('Lead form')->schema([
                    Repeater::make('interest_options')->label('“I’m interested in” options')->grid(3)
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                        ->schema([TextInput::make('label')->required()])->columnSpanFull(),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        SiteContent::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $this->form->getState()],
        );

        Notification::make()->success()->title('University page defaults saved')->send();
    }

    // No getFormActions(): the Blade view renders its own submit button, because
    // the cached-form-actions helper does not exist on a custom Page here.
}
