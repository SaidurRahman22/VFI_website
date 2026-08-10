<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6A — partner identity + tenancy (docs §1). Three tables:
 *   - partner_agencies       — THE tenant (staff-managed; not agency-scoped)
 *   - partner_agency_members — seats; the ONLY tenant-scoped table here, so it
 *     carries `agency_id` + BelongsToAgency + Postgres RLS
 *   - partner_applications   — held for review; separate from the live tenant so
 *     a rejected/duplicate application never pollutes partner_agencies
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_agencies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('country', 90);
            $table->string('city', 90)->nullable();
            $table->string('status', 20)->default('pending_review');   // App\Enums\AgencyStatus
            $table->unsignedBigInteger('tier_id')->nullable();
            $table->unsignedSmallInteger('seat_limit')->default(2);
            $table->unsignedBigInteger('wallet_id')->nullable();        // FK stub (Phase 9)
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();
            $table->index(['legal_name', 'country']);   // duplicate-agency soft signal
        });

        // The one tenant-scoped table in P6 → agency_id + BelongsToAgency + RLS.
        Schema::create('partner_agency_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('seat_role', 20);                // App\Enums\SeatRole
            $table->string('contact_person_name')->nullable();
            $table->string('work_email')->nullable();
            $table->string('phone_cc', 8)->nullable();
            $table->string('phone_national', 20)->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->string('status', 12)->default('active');   // App\Enums\MemberStatus
            $table->timestamps();
            $table->unique(['agency_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('partner_applications', function (Blueprint $table) {
            $table->id();
            $table->string('agency_name');
            $table->string('country', 90);
            $table->string('city', 90)->nullable();
            $table->string('contact_person');
            $table->string('work_email');
            $table->string('phone_cc', 8)->nullable();
            $table->string('phone_national', 20)->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();   // pending_verification
            $table->string('terms_accepted_version', 40)->nullable();
            $table->boolean('authorised_signatory_attested')->default(false);
            $table->unsignedTinyInteger('email_change_count')->default(0);   // rate-limit (max 2)
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->string('review_status', 12)->default('pending');   // App\Enums\ApplicationReviewStatus
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('agency_id')->nullable()->constrained('partner_agencies')->nullOnDelete();  // set on approval
            $table->timestamps();
            $table->index('review_status');
            $table->index(['agency_name', 'country']);
        });

        // Postgres second net (docs §2). RLS FORCE keyed on SET app.agency_id;
        // removing the Eloquent scope then returns ZERO rows, never a competitor's.
        // Guarded so SQLite dev/CI is unaffected — the app-layer scope is primary.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE partner_agency_members ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE partner_agency_members FORCE ROW LEVEL SECURITY');
            DB::statement(<<<'SQL'
                CREATE POLICY partner_agency_members_tenant ON partner_agency_members
                USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)
                WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_applications');
        Schema::dropIfExists('partner_agency_members');
        Schema::dropIfExists('partner_agencies');
    }
};
