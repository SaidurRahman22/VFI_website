<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9B — GDPR / retention operations.
 *
 *  1. data_subject_requests — the register of export and erasure requests, so
 *     "did we answer that request, when, and who did it" is a record rather
 *     than someone's memory. Regulators ask for this, not for the code.
 *  2. document_disclosures — who OUTSIDE VFI received a document, when, and on
 *     what lawful basis. Distinct from document_access_log, which records who
 *     *viewed* a file inside the system: access is not disclosure.
 *  3. retention columns on document_files — a per-file clock, and the moment
 *     the bytes were destroyed. Metadata and audit rows deliberately survive
 *     byte deletion: proving you deleted something requires keeping the proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12);                    // export | erasure
            $table->string('subject_type', 12);            // student | user
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_email', 190)->nullable();   // survives erasure of the row
            $table->string('status', 12)->default('pending');   // pending|completed|blocked|failed
            $table->text('reason')->nullable();                 // why it was raised
            $table->text('outcome')->nullable();                // what happened / what was held back
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('artifact_path', 300)->nullable();   // export bundle on the private disk
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['type', 'status']);
        });

        Schema::create('document_disclosures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_file_id')->nullable()->constrained('document_files')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('recipient_name', 190);
            $table->string('recipient_type', 20);          // university | lender | government | other
            $table->string('lawful_basis', 30);            // consent | contract | legal_obligation | legitimate_interest
            $table->text('note')->nullable();
            $table->foreignId('disclosed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disclosed_at');
            $table->timestamp('created_at')->nullable();   // append-only: no updated_at

            $table->index(['student_id', 'disclosed_at']);
            $table->index('document_file_id');
        });

        Schema::table('document_files', function (Blueprint $table) {
            // when the bytes may be destroyed; null = no clock set yet
            $table->date('retention_until')->nullable()->after('scan_status');
            // set when the blob was purged — the row itself is KEPT as evidence
            $table->timestamp('bytes_deleted_at')->nullable()->after('retention_until');
            $table->index('retention_until');
        });
    }

    public function down(): void
    {
        Schema::table('document_files', function (Blueprint $table) {
            $table->dropIndex(['retention_until']);
            $table->dropColumn(['retention_until', 'bytes_deleted_at']);
        });
        Schema::dropIfExists('document_disclosures');
        Schema::dropIfExists('data_subject_requests');
    }
};
