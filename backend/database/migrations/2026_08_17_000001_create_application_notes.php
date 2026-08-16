<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9A slice 2 — STAFF-INTERNAL counsellor notes on an application.
 *
 * Deliberately a separate table from `application_status_events.note`:
 *   - the status-event note is the transition reason and belongs to the
 *     pipeline history;
 *   - these are private working notes. They are never serialised to a partner
 *     or student endpoint (settled with the client: staff-internal only).
 *
 * Append-only by design: no updated_at and no edit path. A correction is a new
 * note, so the record of what a counsellor believed at each point survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 120)->nullable();   // kept if the account is later deleted
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_notes');
    }
};
