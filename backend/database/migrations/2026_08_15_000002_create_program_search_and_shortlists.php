<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8A — the denormalised flat search table (docs §2) + tenant-scoped
 * shortlists. `program_search` holds ONE row per program-intake (~300–600k at
 * full scale). It is PORTABLE by design so the same query runs on SQLite (tests)
 * and Postgres (prod):
 *   - free text → a lowercased `search_blob` matched with LIKE;
 *   - the ~40 requirement/label/quick-filter facets → space-delimited, space-
 *     padded `flags` tokens (a chip becomes `flags LIKE '% token %'`), incl.
 *     explicit WAIVER tokens for the negative filters (docs §2);
 *   - scalars (country/level/intake/tuition/deadline) → indexed columns.
 * Postgres additionally gets pg_trgm GIN indexes for typeahead speed. The
 * catalogue is public reference data — no PII lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_search', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('institution_id');
            $table->string('title');
            $table->string('university_name');
            $table->string('country', 90);
            $table->string('province_state', 90)->nullable();
            $table->string('level', 60);
            $table->string('study_area', 60)->nullable();
            $table->string('discipline_area', 90)->nullable();
            $table->string('duration_band', 30)->nullable();
            $table->unsignedBigInteger('tuition_fee_minor')->nullable();
            $table->string('tuition_currency', 3)->nullable();
            $table->date('application_deadline_at')->nullable();
            $table->integer('offer_tat_days')->nullable();
            $table->unsignedTinyInteger('intake_month')->nullable();
            $table->unsignedSmallInteger('intake_year')->nullable();
            $table->string('season_label', 20)->nullable();
            $table->text('search_blob');                 // lowercased title + university (LIKE)
            $table->text('flags');                       // " stem coop waive_gre … " tokens
            $table->boolean('is_stale')->default(false);
            $table->string('source', 40)->default('seed');
            $table->timestamps();

            $table->unique(['program_id', 'intake_month', 'intake_year']);
            $table->index(['country', 'level', 'intake_year', 'intake_month']);
            $table->index(['application_deadline_at', 'tuition_fee_minor']);
            $table->index(['is_stale', 'country']);
        });

        // Postgres-only speed indexes (typeahead + facet-token match). Guarded so
        // SQLite/MySQL dev is unaffected; the query itself stays portable.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX ix_ps_blob_trgm ON program_search USING gin (search_blob gin_trgm_ops)');
            DB::statement('CREATE INDEX ix_ps_flags_trgm ON program_search USING gin (flags gin_trgm_ops)');
        }

        // Tenant-scoped shortlist of programs saved to a student.
        Schema::create('program_shortlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['agency_id', 'student_id', 'program_id']);
            $table->index(['agency_id', 'student_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE program_shortlists ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE program_shortlists FORCE ROW LEVEL SECURITY');
            DB::statement(
                'CREATE POLICY program_shortlists_tenant ON program_shortlists '.
                "USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint) ".
                "WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('program_shortlists');
        Schema::dropIfExists('program_search');
    }
};
