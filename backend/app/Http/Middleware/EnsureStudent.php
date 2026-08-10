<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/student/* (Phase 4C). Runs after auth:sanctum. Fail-closed: the
 * session must have been established through the STUDENT sign-in (active_scope
 * = 'student') AND the user must hold the student role — so an admin/partner
 * session can never cross into student routes, and vice-versa. Responses are
 * marked no-store (authed content is never cached).
 */
class EnsureStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $scope = $request->hasSession() ? $request->session()->get('active_scope') : null;

        if (! $user || $scope !== 'student' || ! $user->hasRole(Role::Student)) {
            return response()->json(['message' => 'Unauthenticated.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
