<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Stored lowercased (see User::email mutator) so the unique index is
            // effectively case-insensitive on any DB — the portable stand-in for
            // Postgres citext (docs §1.1).
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');                 // argon2id hash (config/hashing.php)

            // ---- account state / lockout (Phase 1 §1.1) ----
            $table->string('status', 20)->default('active');   // App\Enums\UserStatus
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();

            // ---- mandatory TOTP for admin/staff (Phase 1 §3.3) ----
            $table->text('mfa_secret')->nullable();            // encrypted at rest (cast)
            $table->timestamp('mfa_enrolled_at')->nullable();
            $table->unsignedBigInteger('mfa_last_used_slice')->nullable();  // replay guard

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();                              // deleted_at
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
