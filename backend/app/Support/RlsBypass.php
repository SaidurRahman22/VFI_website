<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Phase 7 — run ONE bootstrap query with the Postgres RLS tenant policy stood
 * down, for the handful of legitimate flows that must read a tenant-keyed row
 * BEFORE any tenant is known:
 *
 *   - partner sign-in resolving a user's seat (no agency bound yet);
 *   - the PUBLIC referral resolver behind the QR landing page;
 *   - capturing / converting a QR referral during student registration + verify.
 *
 * Without this the policy hides every row (app.agency_id is unset) and the flow
 * fails closed — which is safe, but wrong for these paths.
 *
 * Safety: the flag wraps a single closure and is always reset in `finally`; no
 * request-handling path leaves it on (EnsurePartner rebinds app.agency_id for
 * every console request). The policies' WITH CHECK clauses deliberately carry
 * NO bypass, so WRITES still require a real tenant. No-op off Postgres.
 */
class RlsBypass
{
    public static function run(callable $fn): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $fn();
        }

        DB::statement("SET app.rls_bypass = 'on'");
        try {
            return $fn();
        } finally {
            DB::statement("SET app.rls_bypass = ''");
        }
    }
}
