<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9A slice 5 — the record of every deliberate cross-tenant read.
 *
 * `document_access_log` covers who opened a FILE. This covers who opened a
 * PERSON's record while intentionally standing outside the tenancy boundary,
 * and — the part that matters for a data-protection review — WHY they said they
 * needed to.
 *
 * Append-only: no updated_at, no delete path in the app. A reason you can edit
 * afterwards is not an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email', 190)->nullable();     // survives account deletion
            $table->string('subject_type', 40);                 // student | application | …
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('subject_agency_id')->nullable();  // whose tenant was entered
            $table->string('reason', 500);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            // the two questions asked of this table: "who saw X?" and
            // "what did person Y look at?"
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_access_log');
    }
};
