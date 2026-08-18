<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Holds the current request's tenant (agency) id. Registered as a singleton so
 * the BelongsToAgency global scope and the Postgres RLS binding read the same
 * value. It is set ONLY from the authenticated session (partner auth middleware,
 * P6) or explicitly in tests — NEVER from a request parameter (docs §5.1).
 *
 * Fail-closed: when no agency is set, tenant-scoped queries match nothing rather
 * than leaking across tenants.
 *
 * WHY THIS CLASS TOUCHES THE DATABASE
 *
 * There are two nets, and they used to be settable independently: this object
 * for the Eloquent scope, and the Postgres GUC `app.agency_id` for the RLS
 * policies. Binding one without the other is silent on SQLite, which has no
 * row-level security, and fatal on Postgres — every INSERT into a tenant table
 * is rejected by the policy's WITH CHECK with SQLSTATE 42501.
 *
 * EnsurePartner set both as two adjacent lines that had to be remembered
 * together, and nothing else did. Running the suite against real Postgres for
 * the first time turned up 122 failures from exactly that gap. Rather than add
 * the second line to every one of them, the binding now lives HERE, so the two
 * nets cannot be set apart. TenantScope::runAs() keeps its own explicit
 * handling because it also has to restore the previous value.
 */
class TenantContext
{
    private ?int $agencyId = null;

    public function setAgencyId(?int $id): void
    {
        $this->agencyId = $id;
        $this->bindDatabase($id);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function has(): bool
    {
        return $this->agencyId !== null;
    }

    public function clear(): void
    {
        $this->agencyId = null;
        $this->bindDatabase(null);
    }

    /**
     * Keep the Postgres RLS binding in step with the value above.
     *
     * A no-op on any other driver. Failures are swallowed on purpose: this is
     * called from middleware and from test setUp, and a connection that is not
     * up yet must not turn tenant bookkeeping into a fatal error — the scope
     * above is still fail-closed, so the worst case stays "sees nothing", never
     * "sees another tenant".
     */
    private function bindDatabase(?int $id): void
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                return;
            }

            // SET takes no bindings, so the int cast is what keeps this safe.
            // An empty string rather than RESET: the policies compare against
            // current_setting(..., true) and treat '' as "no tenant", and RESET
            // would fall back to a postgresql.conf default if one ever existed.
            $id === null
                ? DB::statement("SET app.agency_id = ''")
                : DB::statement("SET app.agency_id = '".(int) $id."'");
        } catch (Throwable) {
            // no usable connection — nothing to bind
        }
    }
}
