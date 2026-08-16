<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9A fix — admit an audited staff read to the console tables.
 *
 * `2026_08_14_000002` put RLS FORCE on the console tables with a policy whose
 * USING clause matches only `app.agency_id`. That is right for partners, but it
 * also means a VFI staff member — who holds no tenant — sees ZERO rows, and
 * FORCE applies to the table owner too, so even raw SQL returns nothing. The
 * Phase 9A staff application queue was therefore empty on production while
 * passing every SQLite test, which cannot express RLS at all.
 *
 * The two tables staff must survey across tenants get the same treatment the
 * member and referral policies already have: the row is also visible when
 * `app.rls_bypass` is explicitly 'on' (App\Support\RlsBypass sets it for a
 * single closure and always clears it in `finally`).
 *
 * WITH CHECK deliberately keeps NO bypass — a staff WRITE must still adopt the
 * owning tenant via App\Support\TenantScope::runAs(), so nothing can be written
 * into a tenant that was never named.
 *
 * The remaining console tables (program_requests, program_request_documents)
 * are intentionally left strict: no staff screen reads them yet, and the
 * narrower the bypass, the better.
 */
return new class extends Migration
{
    private const TABLES = ['applications', 'application_status_events'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;   // SQLite/MySQL have no RLS — the Eloquent scope is the net
        }

        foreach (self::TABLES as $t) {
            DB::statement("DROP POLICY IF EXISTS {$t}_tenant ON {$t}");
            DB::statement(
                "CREATE POLICY {$t}_tenant ON {$t} ".
                "USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint ".
                "       OR current_setting('app.rls_bypass', true) = 'on') ".
                "WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $t) {
            DB::statement("DROP POLICY IF EXISTS {$t}_tenant ON {$t}");
            DB::statement(
                "CREATE POLICY {$t}_tenant ON {$t} ".
                "USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint) ".
                "WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)"
            );
        }
    }
};
