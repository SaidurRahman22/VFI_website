<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `action` was varchar(20), sized when the vocabulary was create|update|delete.
 * It has since grown names that do not fit — `application_transition` (22),
 * `partner_document_upload` (23), `partner_document_delete` (23),
 * `retention_clock_default` (23) — and Postgres rejects an over-length value
 * outright (SQLSTATE 22001). Because the audit write sits inside the same unit
 * of work as the thing it records, that rejection took the whole request down:
 * a partner document upload and a staff status move both 500'd in production.
 *
 * The tests never saw it. They run on SQLite, which does not enforce varchar
 * length, so every one of those writes silently succeeded locally. This is the
 * same Postgres-only blind spot that has bitten this codebase before, and the
 * durable fix is running the suite against Postgres — not a wider column.
 * ContentAuditLogWidthTest is the stopgap that catches it on SQLite meanwhile.
 *
 * 64 is chosen to be past arguing about: increasing a varchar length in
 * Postgres is a catalogue-only change, so this does not rewrite the table and
 * does not lock it against the append-only writes this table exists for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_audit_log', function (Blueprint $table) {
            $table->string('action', 64)->change();
        });
    }

    public function down(): void
    {
        // Deliberately not narrowing back: rows written since this migration ran
        // may hold names longer than 20, and Postgres would refuse the change (or
        // silently need them truncated). Losing audit history to a rollback is a
        // worse outcome than leaving the column wide.
    }
};
