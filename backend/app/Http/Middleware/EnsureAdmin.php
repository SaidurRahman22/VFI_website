<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/admin/* (docs §2, §3). Runs after auth:sanctum, so the user is
 * already authenticated via the same-origin session cookie. Here we require an
 * admin-panel role AND completed TOTP enrolment — a valid password alone can
 * never reach an admin route. Every admin response is marked no-store.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $mfaOk = ! config('auth.admin_require_totp', true) || ($user && $user->hasMfa());

        if (! $user || ! $user->usesAdminPanel() || ! $mfaOk) {
            return response()->json(['message' => 'Forbidden.'], 403)
                ->header('Cache-Control', 'no-store');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
