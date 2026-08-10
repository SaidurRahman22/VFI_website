<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * auth_events — append-only security audit log (docs §1.4).
 * Every auth-relevant action lands here and is NEVER updated or deleted.
 * Enforced two ways:
 *   - Postgres: REVOKE UPDATE, DELETE from the app role at migrate time.
 *   - Everywhere: the AuthEvent model blocks updates/deletes at the app layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();   // null for unknown-account attempts
            $table->string('email')->nullable()->index();        // attempted email, lowercased
            $table->string('event', 40)->index();                // login_success, login_failed, totp_* …
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();                 // PII/secret-scrubbed extras only
            $table->timestamp('created_at')->useCurrent();       // append-only: no updated_at
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // App role can INSERT + SELECT, never mutate history.
            DB::statement('REVOKE UPDATE, DELETE ON auth_events FROM CURRENT_USER');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
