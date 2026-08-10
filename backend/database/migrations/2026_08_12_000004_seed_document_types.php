<?php

use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 5A — document_types is reference data the app cannot run without, so it
 * is seeded via a migration (the git-sync deploy runs `migrate`, not `db:seed`).
 * Idempotent (the seeder upserts on `key`); also populates every test DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DocumentTypeSeeder)->run();
    }

    public function down(): void
    {
        \App\Models\Student\DocumentType::query()->delete();
    }
};
