<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin_invites — invite-only admin creation (docs §6.1). There is NO admin
 * sign-up UI, ever. A superadmin issues an expiring, single-use invite; the
 * raw token is emailed once and only its hash is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();               // lowercased
            $table->string('role', 30);                     // admin-panel role being granted
            $table->string('token_hash', 64)->unique();     // sha-256 of the raw token; raw never stored
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();   // single-use: set on acceptance
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invites');
    }
};
