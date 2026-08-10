<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate + tenant-bind for /api/partner/* (docs §2, §6). Runs after auth:web.
 * Fail-closed: requires a completed PARTNER sign-in (active_scope=partner) with
 * a bound agency AND a partner role. The tenant id is read from the SESSION
 * ONLY — never a request param/query/body — and pushed into both nets:
 *   - TenantContext (the Eloquent BelongsToAgency global scope)
 *   - Postgres `app.agency_id` (the RLS FORCE second net)
 * The pg setting is reset in terminate() so it can never bleed to another
 * request on a reused connection.
 */
class EnsurePartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $session = $request->hasSession() ? $request->session() : null;
        $scope = $session?->get('active_scope');
        $agencyId = $session?->get('active_partner_agency_id');
        $isPartner = $user && ($user->hasRole(Role::PartnerOwner) || $user->hasRole(Role::PartnerCounsellor));

        if (! $user || $scope !== 'partner' || ! $agencyId || ! $isPartner) {
            return response()->json(['message' => 'Unauthenticated.'], 401)->header('Cache-Control', 'no-store');
        }

        // Bind the tenant from the session — the ONLY source of truth.
        app(TenantContext::class)->setAgencyId((int) $agencyId);
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET app.agency_id = '".(int) $agencyId."'");
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::statement("SET app.agency_id = ''");
            } catch (\Throwable $e) {
                // best-effort reset; a fresh php-fpm connection also clears it
            }
        }
    }
}
