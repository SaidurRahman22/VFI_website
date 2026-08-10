<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7A — the partner console's tenant-scoped data (docs §4–6). Every table
 * here carries `agency_id` (BelongsToAgency + Postgres RLS FORCE) and is
 * console-only, so — unlike `students` — the global scope + RLS apply with no
 * dual-use concern. `agency_id` is denormalised onto child rows so scoping never
 * needs a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedBigInteger('program_id')->nullable();
            $table->unsignedBigInteger('institution_id')->nullable();
            $table->string('intake_month', 20)->nullable();
            $table->unsignedSmallInteger('intake_year')->nullable();
            $table->string('status', 30)->default('submitted');   // App\Enums\ApplicationStatus
            $table->string('ack_no', 60)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->string('deferred_to_intake', 40)->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'status']);
            $table->index(['agency_id', 'deadline_at']);
        });

        // Append-only pipeline history (docs §4). agency_id denormalised.
        Schema::create('application_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->timestamp('occurred_at');
            $table->string('actor_type', 20);              // App\Enums\ActorType
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('application_id');
        });

        Schema::create('program_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('enquiry_type', 12);            // new | existing
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('first_name', 60)->nullable();
            $table->string('last_name', 70)->nullable();
            $table->string('email')->nullable();
            $table->string('country_of_education', 90)->nullable();
            $table->string('highest_education_level', 90)->nullable();
            $table->string('destination', 90)->nullable();
            $table->string('preferred_study_area', 120)->nullable();
            $table->string('preferred_study_level', 90)->nullable();
            $table->string('program_label', 160)->nullable();
            $table->text('additional_info')->nullable();
            $table->string('channel', 12)->default('console');   // console | whatsapp
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->index(['agency_id', 'status']);
        });

        // Enquiry academic docs — reuse the Phase 5 scan-gate (private disk,
        // unreadable until clean). Self-contained blob metadata.
        Schema::create('program_request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_request_id')->constrained('program_requests')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->string('storage_key');
            $table->string('original_filename', 120);
            $table->string('content_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->nullable();
            $table->string('scan_status', 12)->default('pending');   // App\Enums\ScanStatus
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->index('program_request_id');
        });

        Schema::create('agency_referral_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->string('slug', 64)->unique();          // opaque, unguessable
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('agency_id');
        });

        Schema::create('referral_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->foreignId('referral_link_id')->constrained('agency_referral_links')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('ref_code_seen', 64)->nullable();
            $table->timestamp('landed_at');
            $table->timestamp('converted_at')->nullable();   // set once email is verified (attribution counts)
            $table->string('channel', 8)->default('qr');     // qr | link
            $table->timestamps();
            $table->index('agency_id');
        });

        // Postgres RLS FORCE on every agency-scoped console table.
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach ([
                'applications', 'application_status_events', 'program_requests',
                'program_request_documents', 'agency_referral_links', 'referral_signups',
            ] as $t) {
                DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
                DB::statement(
                    "CREATE POLICY {$t}_tenant ON {$t} ".
                    "USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint) ".
                    "WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_signups');
        Schema::dropIfExists('agency_referral_links');
        Schema::dropIfExists('program_request_documents');
        Schema::dropIfExists('program_requests');
        Schema::dropIfExists('application_status_events');
        Schema::dropIfExists('applications');
    }
};
