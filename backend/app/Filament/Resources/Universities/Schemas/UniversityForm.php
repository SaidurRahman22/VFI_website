<?php

namespace App\Filament\Resources\Universities\Schemas;

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
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(180)->columnSpanFull(),
                TextInput::make('tagline')->maxLength(190)->columnSpanFull()
                    ->helperText('One line shown under the name, e.g. “Russell Group · one-year master’s”.'),
                TextInput::make('country')->required()->maxLength(90),
                TextInput::make('province_state')->label('Province / State')->maxLength(90),
                TextInput::make('city')->maxLength(90),
                TextInput::make('website')->url()->maxLength(190)->prefix('https://'),
            ]),

            Section::make('Branding')->columns(2)->schema([
                FileUpload::make('logo_key')->label('Logo')->image()->imageEditor()
                    ->disk('public')->directory('media/universities')->visibility('public')->maxSize(2048)
                    ->helperText('Square logo works best. Leave empty to show an initials badge.'),
                FileUpload::make('hero_image_key')->label('Hero image')->image()->imageEditor()
                    ->disk('public')->directory('media/universities')->visibility('public')->maxSize(4096),
            ]),

            Section::make('At a glance')->columns(2)->schema([
                Toggle::make('vfi_represented')->label('VFI partner'),
                Toggle::make('is_major_city')->label('Major city'),
                Toggle::make('has_own_english_test')->label('Has own English test'),
                Toggle::make('interview_required')->label('Interview required'),
                Select::make('affordability_band')->options(['low' => 'Affordable', 'medium' => 'Moderate', 'high' => 'Premium']),
                Select::make('offer_tat_band')->label('Offer speed')->options(['fast' => 'Fast', 'standard' => 'Standard', 'slow' => 'Slow']),
                Select::make('offer_acceptance_band')->label('Offer acceptance')->options(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']),
                Select::make('tuition_deposit_policy')->label('Tuition deposit')->options(['none' => 'None', 'low' => 'Low', 'standard' => 'Standard']),
            ]),

            Section::make('Overview')->schema([
                Textarea::make('overview')->rows(5)->columnSpanFull()
                    ->helperText('The “About” paragraph. Plain text; line breaks are kept.'),
            ]),

            Section::make('Ranking')->columns(3)->schema([
                TextInput::make('ranking_world')->label('World rank')->maxLength(60)->placeholder('#54'),
                TextInput::make('ranking_national')->label('National rank')->maxLength(60),
                TextInput::make('ranking_note')->label('Note')->maxLength(190)->columnSpan(3),
            ]),

            Section::make('Cost to study')->columns(2)->schema([
                Textarea::make('cost_note')->rows(3)->columnSpanFull(),
                TextInput::make('living_cost_note')->label('Living cost')->maxLength(190),
                TextInput::make('accommodation_note')->label('Accommodation')->maxLength(190),
            ]),

            Section::make('Scholarships')->schema([
                Repeater::make('scholarships_json')->label('Scholarships')->collapsed()->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('level')->placeholder('UG / PG'),
                        TextInput::make('amount')->placeholder('£5,000'),
                        TextInput::make('note'),
                    ]),
            ]),

            Section::make('Admissions')->columns(2)->schema([
                Textarea::make('admission_academic')->label('Academic requirements')->rows(3),
                Textarea::make('admission_english')->label('English requirements')->rows(3),
            ]),

            Section::make('Placements')->columns(2)->schema([
                Textarea::make('placement_note')->rows(3)->columnSpanFull(),
                TextInput::make('salary_note')->label('Average salary note')->maxLength(190)->columnSpanFull(),
                Repeater::make('recruiters_json')->label('Top recruiters')->columns(1)->grid(3)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([TextInput::make('name')->required()])->columnSpanFull(),
            ]),

            Section::make('Gallery')->schema([
                FileUpload::make('gallery_json')->label('Photos')->image()->multiple()->reorderable()
                    ->disk('public')->directory('media/universities/gallery')->visibility('public')->maxSize(4096)->columnSpanFull(),
            ]),

            Section::make('FAQs')->schema([
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
