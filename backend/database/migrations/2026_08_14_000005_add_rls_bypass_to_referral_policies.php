<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 hotfix (2/2) — the QR referral flows read a tenant-keyed row BEFORE
 * any tenant exists, in three PUBLIC paths: the landing-page resolver, the
 * `?ref=` capture during student registration, and convert-on-verify. Under RLS
 * FORCE the policy hid every row, so a valid slug 404'd and attribution never
 * happened (production-only; SQLite has no RLS).
 *
 * Both policies now also admit rows when `app.rls_bypass` is explicitly 'on'
 * (set only by App\Support\RlsBypass around a single closure, reset in
 * `finally`). WITH CHECK carries NO bypass — writes still require a real
 * tenant, so cross-tenant isolation is unchanged.
 */
return new class extends Migration
{
    private const TABLES = ['agency_referral_links', 'referral_signups'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
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
