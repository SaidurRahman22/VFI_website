<?php

namespace App\Filament\Resources\DocumentReviews;

use App\Enums\DocumentStatus;
use App\Filament\Resources\DocumentReviews\Pages\ListDocumentReviews;
use App\Filament\Resources\DocumentReviews\Tables\DocumentReviewsTable;
use App\Models\Student\StudentDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 9A — the staff queue for checking student documents.
 *
 * Read-only as a resource (no create/edit form): a document is only ever changed
 * through DocumentReviewService, so every decision goes down one guarded, audited
 * path. The row actions on the table are the entire write surface.
 */
class DocumentReviewResource extends Resource
{
    protected static ?string $model = StudentDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Document reviews';

    protected static ?string $modelLabel = 'document review';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return DocumentReviewsTable::configure($table);
    }

    /** Only documents that actually have a file — `missing` rows are noise. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('file_id')
            ->with(['student', 'documentType', 'file']);
    }

    /**
     * Badge the nav with how many are waiting. Cached — this fires on every
     * admin page render, and a COUNT per request adds up fast once the document
     * table is large. 60s is fresh enough for a queue badge.
     */
    public static function getNavigationBadge(): ?string
    {
        $n = Cache::remember('badge:document-reviews', 60, fn () => static::getModel()::query()
            ->where('status', DocumentStatus::Uploaded->value)
            ->whereNotNull('file_id')->count());

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentReviews::route('/'),
        ];
    }
}
