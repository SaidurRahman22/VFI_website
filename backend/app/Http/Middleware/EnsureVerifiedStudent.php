<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 5 (docs §5.5) — the `must_verify` gate. Runs AFTER EnsureStudent, so a
 * student session already exists. An unverified student may browse the portal
 * (Phase 4 policy) but may NOT upload documents or submit applications until
 * their email is verified. Fail-closed with a 403 the client can act on.
 */
class EnsureVerifiedStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->email_verified_at === null) {
            return response()->json([
                'message' => 'Please verify your email address before uploading documents.',
                'must_verify' => true,
            ], 403)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
