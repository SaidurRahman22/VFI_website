<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — append-only content audit trail (docs §7.1). A before/after row for
 * every create/update/delete/reorder/import/reset/toggle_page, written inside
 * the same transaction as the mutation. Never updated or deleted:
 *   - Postgres: REVOKE UPDATE, DELETE from the app role.
 *   - Everywhere: the model blocks updates/deletes at the app layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->index();
            $table->string('action', 20)->index();     // create|update|delete|reorder|import|reset|toggle_page
            $table->string('entity', 40)->index();      // collection/singleton kind
            $table->string('entity_id', 64)->nullable(); // legacy_id or singleton key
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();  // append-only: no updated_at
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('REVOKE UPDATE, DELETE ON content_audit_log FROM CURRENT_USER');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_audit_log');
    }
};
