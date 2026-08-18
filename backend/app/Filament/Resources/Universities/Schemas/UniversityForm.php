<?php

namespace App\Filament\Resources\Universities\Schemas;

use App\Services\ImageOptimiser;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Phase 8+ — the staff authoring form for a university profile. The core
 * identity fields feed the directory + cards; the editorial sections power the
 * rich public detail page. Ingested catalogue rows (US/DE) can be enriched here;
 * a brand-new university can be created from scratch.
 */
class UniversityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->description('Header of the university detail page, and the name/city shown on every search result card.')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(180)->columnSpanFull()->helperText('The official name. Shown as the page title and on every search card.'),
                TextInput::make('tagline')->maxLength(190)->columnSpanFull()
                    ->helperText('One line shown under the name, e.g. “Russell Group · one-year master’s”.'),
                TextInput::make('country')->required()->maxLength(90)->helperText('Drives the country filter in Search. Must match the taxonomy spelling exactly.'),
                TextInput::make('province_state')->label('Province / State')->maxLength(90),
                TextInput::make('city')->maxLength(90)->helperText('Shown under the name on the detail page and on search cards.'),
                TextInput::make('website')->url()->maxLength(190)->prefix('https://')->helperText('The "Visit website" link. Filled from the feed for US universities.'),
            ]),

            Section::make('Branding')->description('The logo badge and the banner photo at the top of the detail page. Both are re-encoded on upload.')->columns(2)->schema([
                FileUpload::make('logo_key')->label('Logo')->image()->imageEditor()
                    ->disk('public')->directory('media/universities')->visibility('public')->maxSize(2048)
                    // Re-encoded on save (downscale + EXIF strip). The cap is per surface: a
                    // logo rendered at ~120px has no use for a 2000px original.
                    ->saveUploadedFileUsing(ImageOptimiser::storeOptimised(800))
                    ->helperText('Square logo works best. Leave empty to show an initials badge.'),
                FileUpload::make('hero_image_key')->label('Hero image')->image()->imageEditor()
                    ->disk('public')->directory('media/universities')->visibility('public')->maxSize(4096)
                    ->saveUploadedFileUsing(ImageOptimiser::storeOptimised(2400)),
            ]),

            Section::make('At a glance')->description('Drives the filter chips in programme Search. These are not shown as text - they decide which searches this university appears in.')->columns(2)->schema([
                Toggle::make('vfi_represented')->label('VFI partner'),
                Toggle::make('is_major_city')->label('Major city'),
                Toggle::make('has_own_english_test')->label('Has own English test'),
                Toggle::make('interview_required')->label('Interview required'),
                Select::make('affordability_band')->options(['low' => 'Affordable', 'medium' => 'Moderate', 'high' => 'Premium']),
                Select::make('offer_tat_band')->label('Offer speed')->options(['fast' => 'Fast', 'standard' => 'Standard', 'slow' => 'Slow']),
                Select::make('offer_acceptance_band')->label('Offer acceptance')->options(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']),
                Select::make('tuition_deposit_policy')->label('Tuition deposit')->options(['none' => 'None', 'low' => 'Low', 'standard' => 'Standard']),
            ]),

            Section::make('Overview')->description('The "Overview" tab of the detail page: the About paragraph and the three stat tiles beside it.')->schema([
                Textarea::make('overview')->rows(5)->columnSpanFull()
                    ->helperText('The “About” paragraph. Plain text; line breaks are kept.'),
                Repeater::make('overview_stats_json')->label('Stat tiles')->columns(2)->grid(3)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema([
                        TextInput::make('value')->placeholder('74%')->required(),
                        TextInput::make('label')->placeholder('Acceptance rate')->required(),
                    ])->columnSpanFull()->helperText('Three tiles read best, e.g. acceptance rate / international students / graduate employment.'),
            ]),

            Section::make('Ranking')->description('The "Ranking" tab. No feed supplies rankings, so anything here is typed by staff.')->schema([
                Repeater::make('rankings_json')->label('Ranking cards')->columns(2)->grid(3)
                    ->itemLabel(fn (array $state): ?string => $state['by'] ?? null)
                    ->schema([
                        TextInput::make('rank')->placeholder('#54')->required(),
                        TextInput::make('by')->label('Ranked by')->placeholder('QS World Rankings')->required(),
                    ])->columnSpanFull(),
                TextInput::make('ranking_note')->label('Note')->maxLength(190)->columnSpanFull()->helperText('Small print under the ranking cards, e.g. the year the ranking refers to.'),
            ]),

            Section::make('Intakes')->description('The "Intakes" tab cards. Leave empty and the cards are built from the live course catalogue instead.')->schema([
                Repeater::make('intakes_json')->label('Intake cards')->collapsed()->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([
                        TextInput::make('name')->placeholder('Fall Intake')->required(),
                        TextInput::make('month')->placeholder('September')->helperText('Shown as a pill on the card.'),
                        Textarea::make('note')->rows(2)->columnSpanFull(),
                        FileUpload::make('image')->label('Card image')->image()->imageEditor()
                            ->disk('public')->directory('media/universities/intakes')->visibility('public')->maxSize(3072)
                            ->saveUploadedFileUsing(ImageOptimiser::storeOptimised(1200))
                            ->columnSpanFull()->helperText('Optional. Falls back to the season image set in University page defaults.'),
                    ])->columnSpanFull()
                    ->helperText('Leave empty to build the cards automatically from the course catalogue’s intakes.'),
            ]),

            Section::make('Cost to study')->description('The "Cost to Study" tab table. For US universities these rows arrive from the U.S. Department of Education feed; anything you type here replaces them permanently.')->columns(2)->schema([
                Textarea::make('cost_note')->rows(3)->columnSpanFull()->helperText('Sits above the cost table. For US universities the feed writes the source and reporting year here.'),
                Repeater::make('cost_rows_json')->label('Cost table')->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema([
                        TextInput::make('label')->placeholder('Annual avg PG tuition fee')->required(),
                        TextInput::make('value')->placeholder('USD 25,000')->required(),
                    ])->columnSpanFull(),
                TextInput::make('living_cost_note')->label('Living cost')->maxLength(190)->helperText('One line in the Cost to Study tab. For US universities this is the published room-and-board figure.'),
                TextInput::make('accommodation_note')->label('Accommodation')->maxLength(190)->helperText('One line in the Cost to Study tab. No feed publishes this " type it from the university site.'),
            ]),

            Section::make('Scholarships')->description('The "Scholarships" tab. No feed publishes these, so they are staff-entered.')->schema([
                Repeater::make('scholarships_json')->label('Scholarships')->collapsed()->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('level')->placeholder('UG / PG'),
                        TextInput::make('amount')->placeholder('£5,000'),
                        TextInput::make('note'),
                    ]),
            ]),

            Section::make('Admissions')->description('The "Admissions" tab. SAT/ACT ranges arrive from the feed for US universities; English-test requirements are never published by it and must be typed.')->schema([
                Repeater::make('admissions_json')->label('Requirements by level (shown as tabs)')->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['level'] ?? null)
                    ->schema([
                        TextInput::make('level')->placeholder('Masters')->required()->columnSpanFull(),
                        Textarea::make('academic')->label('Academic requirements')->rows(3)->columnSpanFull(),
                        Textarea::make('english')->label('English proficiency')->rows(2)->columnSpanFull()
                            ->helperText('e.g. TOEFL iBT 79 · IELTS 6.5 · DET 110'),
                        Textarea::make('tests')->label('Standardised tests')->rows(2)->columnSpanFull(),
                    ])->columnSpanFull(),
                Textarea::make('admission_academic')->label('Fallback — academic')->rows(2)->columnSpanFull(),
                Textarea::make('admission_english')->label('Fallback — English')->rows(2)->columnSpanFull(),
            ]),

            Section::make('Placements')->description('The "Placements" tab. Graduate earnings come from the feed for US universities.')->columns(2)->schema([
                TextInput::make('placement_rate')->label('Placement rate')->maxLength(30)->placeholder('92%')->helperText('Big number at the top of the Placements tab. Staff-entered.'),
                TextInput::make('salary_note')->label('Average salary note')->maxLength(190)->helperText('For US universities the feed writes real median earnings 10 years after entry, with its source cited.'),
                Textarea::make('placement_note')->rows(3)->columnSpanFull()->helperText('Paragraph under the placement figures.'),
                TextInput::make('alumni_note')->label('Alumni network note')->maxLength(190)->columnSpanFull()->helperText('One line under the recruiters grid.'),
                Repeater::make('recruiters_json')->label('Top recruiters')->columns(1)->grid(3)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([TextInput::make('name')->required()])->columnSpanFull(),
                Repeater::make('jobs_json')->label('Jobs after graduating (table)')->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['profile'] ?? null)
                    ->schema([
                        TextInput::make('profile')->label('Job profile')->required(),
                        TextInput::make('salary')->label('Average salary')->required(),
                    ])->columnSpanFull(),
            ]),

            Section::make('Life on campus')->description('The "Placements" tab footer and campus blurb on the detail page.')->schema([
                Repeater::make('services_json')->label('Student service blocks (accordion)')->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                    ->schema([
                        TextInput::make('title')->placeholder('Clubs and societies')->required()->columnSpanFull(),
                        Textarea::make('body')->rows(3)->columnSpanFull(),
                    ])->columnSpanFull(),
            ]),

            Section::make('Gallery')->description('The "Gallery" tab. Staff-entered; no feed supplies photographs.')->schema([
                FileUpload::make('gallery_json')->label('Photos')->image()->multiple()->reorderable()
                    ->disk('public')->directory('media/universities/gallery')->visibility('public')->maxSize(4096)->columnSpanFull()
                    ->saveUploadedFileUsing(ImageOptimiser::storeOptimised(2000)),
            ]),

            Section::make('FAQs')->description('The "FAQs" accordion at the bottom of the detail page.')->schema([
                Repeater::make('faqs_json')->label('FAQs')->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['q'] ?? null)
                    ->schema([
                        TextInput::make('q')->label('Question')->required()->columnSpanFull(),
                        Textarea::make('a')->label('Answer')->rows(2)->columnSpanFull(),
                    ]),
            ]),
        ]);
    }
}
