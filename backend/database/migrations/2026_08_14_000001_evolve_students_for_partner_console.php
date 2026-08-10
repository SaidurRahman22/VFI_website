<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7A — the `students` table becomes dual-purpose: it already anchors a
 * PORTAL student (Phase 5, keyed by user_id, self-scoped) and now also holds an
 * AGENCY-registered lead (owned by a partner agency, may have no login yet).
 *
 * `agency_id` (nullable) is the owning agency — null = self-signup/unowned.
 * The console scopes students EXPLICITLY by the session agency (the table has no
 * BelongsToAgency global scope, because the portal path reads it with no tenant
 * in context); the console-only tables get the global scope + RLS instead.
 * `email` (lowercased) is the cross-channel attribution/collision key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();   // agency leads have no login

            $table->foreignId('agency_id')->nullable()->after('user_id')
                ->constrained('partner_agencies')->nullOnDelete();
            $table->string('source', 20)->default('self_signup')->after('agency_id');   // App\Enums\StudentSource
            $table->foreignId('registered_by_user_id')->nullable()->after('source')
                ->constrained('users')->nullOnDelete();

            // Contact identity for agency-registered leads (portal students mirror
            // their User here too, so the collision key + list work uniformly).
            $table->string('email')->nullable()->after('registered_by_user_id');
            $table->string('first_name', 60)->nullable()->after('email');
            $table->string('middle_name', 60)->nullable()->after('first_name');
            $table->string('last_name', 70)->nullable()->after('middle_name');
            $table->string('phone_cc', 8)->nullable()->after('last_name');
            $table->string('phone', 20)->nullable()->after('phone_cc');

            // Console list facets.
            $table->string('destination_country', 90)->nullable()->after('phone');
            $table->string('intake_month', 20)->nullable()->after('destination_country');
            $table->unsignedSmallInteger('intake_year')->nullable()->after('intake_month');

            $table->timestamp('archived_at')->nullable()->after('intake_year');   // console soft-delete

            $table->index('agency_id');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropConstrainedForeignId('registered_by_user_id');
            $table->dropColumn([
                'source', 'email', 'first_name', 'middle_name', 'last_name',
                'phone_cc', 'phone', 'destination_country', 'intake_month', 'intake_year', 'archived_at',
            ]);
        });
    }
};
