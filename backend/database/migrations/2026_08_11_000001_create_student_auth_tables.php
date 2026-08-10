<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A — student identity data layer (docs §Work-breakdown 1–3).
 *
 * Security posture baked into the schema:
 *  - OTP codes and reset tokens are stored HASHED (code_hash / token_hash),
 *    never plaintext.
 *  - OTP state is keyed on an opaque `flow_id`, so the email address never has
 *    to travel in a URL (kills the PII-in-history leak).
 *  - reset tokens carry single-use (consumed_at) + supersede (invalidated_by)
 *    columns so a used or replaced token is provably dead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Phone is collected + formatted at registration but NOT verified (SMS
        // is explicitly out of scope this phase). Nullable identity-adjacent field.
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
        });

        // Hard record of terms consent (the #rg-agree checkbox is stored, not
        // just validated) — document + version + when + where.
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 40)->default('terms');   // 'terms' | 'privacy' | …
            $table->string('version', 40)->default('1');
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'document']);
        });

        // Email OTP flow. One row per issued code, keyed on an opaque flow_id.
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('flow_id')->unique();                  // opaque handle in the URL
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');                            // target (lowercased)
            $table->string('code_hash');                        // argon2id hash of the 6-digit code
            $table->string('purpose', 40)->default('signup_student');
            $table->unsignedTinyInteger('attempts_used')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');                    // now()+10min
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();       // single-use
            $table->string('request_ip', 45)->nullable();
            $table->timestamps();
            $table->index('email');
        });

        // Rich password-reset tokens replace Laravel's default (email/token/created_at)
        // table — the built-in broker is unused; the custom flow needs user_id,
        // hashing, expiry, single-use and supersede tracking.
        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();             // sha256 of 32 CSPRNG bytes
            $table->string('requested_for_email');
            $table->timestamp('expires_at');                    // now()+45min
            $table->timestamp('consumed_at')->nullable();       // single-use
            $table->string('requested_ip', 45)->nullable();
            $table->string('consumed_ip', 45)->nullable();
            $table->string('invalidated_by', 40)->nullable();   // 'superseded' | 'reset' | 'used'
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
        Schema::dropIfExists('terms_acceptances');

        Schema::dropIfExists('password_reset_tokens');
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
