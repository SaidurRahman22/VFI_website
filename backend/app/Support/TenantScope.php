<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Phase 9A — run a block of work AS a given tenant.
 *
 * The staff back-office legitimately writes into tenant-owned tables: advancing
 * an application notifies the owning agency, suspending an agency touches its
 * rows. Staff have no tenant of their own, and:
 *
 *   - the BelongsToAgency scope is fail-closed (no tenant → zero rows), and
 *   - the Postgres RLS policies carry NO bypass on WITH CHECK, so a write
 *     without `app.agency_id` is rejected outright (by design — see RlsBypass).
 *
 * So a staff write must adopt the target tenant for the duration of the write,
 * rather than switch the guard off. This sets BOTH nets — the Eloquent scope's
 * TenantContext and the Postgres GUC — and restores whatever was there before,
 * even if the closure throws.
 *
 * This is deliberately narrow: pass the agency you are acting on, do one unit of
 * work, get out. It is not a way to browse another tenant's data — cross-tenant
 * READS go through an audited path with a reason-for-access.
 *
 * NOTE FOR TESTS: SQLite has no RLS, so a missing runAs() wrapper passes locally
 * and fails only on production Postgres. Any staff write into a tenant table
 * must be wrapped here.
 */
class TenantScope
{
    public static function runAs(int $agencyId, callable $fn): mixed
    {
        $ctx = app(TenantContext::class);
        $previous = $ctx->agencyId();
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        $ctx->setAgencyId($agencyId);
        if ($isPg) {
            // SET takes no bindings; the int cast is what keeps this safe.
            DB::statement("SET app.agency_id = '".(int) $agencyId."'");
        }

        try {
            return $fn();
        } finally {
            $ctx->setAgencyId($previous);
            if ($isPg) {
                $previous === null
                    ? DB::statement('RESET app.agency_id')
                    : DB::statement("SET app.agency_id = '".(int) $previous."'");
            }
        }
    }
}
