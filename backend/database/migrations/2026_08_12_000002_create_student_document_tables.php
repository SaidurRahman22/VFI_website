<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5A — document types + student documents + stored blobs + access log
 * (docs §2, §3). Highest-sensitivity data in the product: the blob key is a
 * server UUID (never the client filename), a file is unreadable until its
 * scan_status is clean, and every read is recorded in an append-only log.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Server-driven checklist (replaces hardcoded DOC_DEFS / VISA_DEFS).
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();        // passport, transcripts, offer, medical, …
            $table->string('pack', 20);                 // application | visa
            $table->string('name', 120);
            $table->string('icon', 40)->nullable();
            $table->text('note')->nullable();
            $table->boolean('destination_dependent')->default(false);  // e.g. medical
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index('pack');
        });

        // The stored blob. Written before scan; readable only once clean.
        Schema::create('document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('storage_key');              // server UUID path on the private disk
            $table->string('original_name', 120);       // sanitised display name only
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64)->nullable();   // dedupe / idempotency
            $table->string('scan_status', 12)->default('pending');   // App\Enums\ScanStatus
            $table->timestamps();
            $table->index(['student_id', 'document_type_id']);
        });

        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('missing');   // App\Enums\DocumentStatus
            $table->foreignId('file_id')->nullable()->constrained('document_files')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['student_id', 'document_type_id']);   // one row per type per student
        });

        // Append-only: actor + time + action on every read of a sensitive file.
        Schema::create('document_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_file_id')->constrained('document_files')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);               // presign | download | upload | scan | delete
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('document_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_log');
        Schema::dropIfExists('student_documents');
        Schema::dropIfExists('document_files');
        Schema::dropIfExists('document_types');
    }
};
