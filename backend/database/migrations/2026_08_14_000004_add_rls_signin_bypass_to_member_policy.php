<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 hotfix — partner sign-in must resolve a user's seat BEFORE any tenant
 * is known, so `app.agency_id` is necessarily unset for that one lookup. Under
 * RLS FORCE the policy therefore hid every row and refused every partner
 * sign-in with the review-gate message (a production-only failure: SQLite has
 * no RLS, so the suite stayed green).
 *
 * The policy now also admits rows when `app.rls_bypass` is explicitly 'on'.
 * That flag is set by exactly one code path — PartnerAuthController::signin,
 * around a single closure, reset in `finally` — and never by request handling
 * (EnsurePartner always rebinds app.agency_id). Tenant isolation for every
 * console read/write is unchanged: with the flag unset the policy is identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS partner_agency_members_tenant ON partner_agency_members');
        DB::statement(<<<'SQL'
            CREATE POLICY partner_agency_members_tenant ON partner_agency_members
            USING (
                agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint
                OR current_setting('app.rls_bypass', true) = 'on'
            )
            WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS partner_agency_members_tenant ON partner_agency_members');
        DB::statement(<<<'SQL'
            CREATE POLICY partner_agency_members_tenant ON partner_agency_members
            USING (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)
            WITH CHECK (agency_id = NULLIF(current_setting('app.agency_id', true), '')::bigint)
        SQL);
    }
};
