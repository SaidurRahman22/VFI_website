<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 9A fix — let the admin panel READ across tenants.
 *
 * The console tables carry Postgres RLS FORCE keyed on `app.agency_id`. VFI
 * staff hold no tenant, so without this every staff screen renders empty — and
 * FORCE applies to the table owner too, so it is not something the ORM can work
 * around. App\Support\RlsBypass cannot help here either: it wraps a closure that
 * runs immediately, whereas Filament builds a query in one method and executes
 * it later during render.
 *
 * So the flag is set for the DURATION OF THE REQUEST instead, and only for the
 * admin panel, which exists precisely to give staff oversight across agencies.
 *
 * This admits READS only. Every policy's WITH CHECK deliberately carries no
 * bypass, so a write still has to name its tenant (App\Support\TenantScope) —
 * staff cannot create or move a row into an agency they never identified.
 *
 * The flag is cleared in `terminate()` as well as on the way out, because
 * connections are pooled between requests and a leaked bypass would quietly
 * disable tenancy for whoever got that connection next.
 */
class StaffRlsRead
{
    private function set(string $value): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET app.rls_bypass = '{$value}'");
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        // The panel is already gated by auth + EnsureAdmin; belt and braces here
        // so an unauthenticated request never turns tenancy off.
        if ($request->user()) {
            $this->set('on');
        }

        try {
            return $next($request);
        } finally {
            $this->set('');
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->set('');
    }
}
