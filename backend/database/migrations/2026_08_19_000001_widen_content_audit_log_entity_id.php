<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `entity_id` was varchar(64), sized when it only ever held a legacy id or a
 * singleton key. The media library writes the stored PATH into it, and those are
 * longer than that:
 *
 *   /storage/media/8e20b17b37ac2f193b9c2404e7c0a5362c07ce6072a298af80fb8dba28a675e1.jpg
 *
 * is 82 characters. Postgres rejects the insert (SQLSTATE 22001), and because
 * the audit row is written inside the same transaction as the thing it records,
 * the media upload itself rolls back. So on production, uploading an image
 * through the content manager fails — with the audit log as the cause.
 *
 * This is the SECOND column on this table to overflow the same way; the first
 * was `action` at varchar(20), which broke every document upload and every staff
 * status move. Both were invisible to the test suite because SQLite does not
 * enforce varchar length. Found this time by running the suite against real
 * Postgres, which is the actual fix for the class — see ContentAuditLogWidthTest
 * and the tests-postgres CI job.
 *
 * 512 rather than 255: this holds a storage path, and a nested media directory
 * plus a 64-character content hash is already 82. Increasing a varchar length in
 * Postgres is catalogue-only, so this neither rewrites nor locks the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_audit_log', function (Blueprint $table) {
            $table->string('entity_id', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not narrowing back: rows written since this ran may hold
        // values longer than 64, and Postgres would refuse the change rather
        // than truncate. Losing audit history to a rollback is the worse outcome.
    }
};
