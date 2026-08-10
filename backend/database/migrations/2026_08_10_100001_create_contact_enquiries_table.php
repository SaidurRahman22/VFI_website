<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — the P0 fix: contact-form leads were silently discarded by
 * contact.html. This table persists them for the staff inbox (docs §7.1).
 * Plain-text fields only; output-encoded when displayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('fname', 120);
            $table->string('phone', 40);
            $table->string('email', 190)->index();
            $table->string('dest', 80)->nullable();       // destination country
            $table->text('msg')->nullable();
            $table->string('status', 20)->default('new')->index();  // new|read|archived
            $table->string('source_page', 190)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();                          // created_at = submitted_at
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiries');
    }
};
