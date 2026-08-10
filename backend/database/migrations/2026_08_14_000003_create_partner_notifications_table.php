<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7F — partner console notifications (docs §7). Tenant-scoped; the page +
 * the bell popover share this one source. read_at is agency-level (single-owner
 * is the common case; per-seat read-state is a later refinement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('partner_agencies')->cascadeOnDelete();
            $table->string('kind', 30)->default('system');   // application | system | …
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'read_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE partner_notifications ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE partner_notifications FORCE ROW LEVEL SECURITY');
            DB::statement(
                "CREATE POLICY partner_notifications_tenant ON partner_notifications ".
                "USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint) ".
                "WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_notifications');
    }
};
