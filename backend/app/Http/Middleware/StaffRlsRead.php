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
        /*
         * Gate on WHO the user is, not on which route group they entered by.
         *
         * This first hung off the Filament panel's middleware, which covers
         * /manage but NOT /livewire/update — and every button in the panel acts
         * through that route. So the page rendered with rows, and the moment
         * anything was clicked the follow-up request ran without the bypass:
         * the table re-rendered to "No applications" and the action could not
         * find its record. No exception, just silently zero rows.
         *
         * Keyed on usesAdminPanel() so it can live in the global web group and
         * still never loosen anything for a partner or a student — they hold no
         * admin role, so the flag is never set for them and their tenancy net is
         * untouched.
         */
        $user = $request->user();
        if ($user && $user->usesAdminPanel()) {
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
