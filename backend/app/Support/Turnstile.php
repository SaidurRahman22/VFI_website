<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile verification for unauthenticated auth writes (docs §5.3).
 * Fail-OPEN when disabled/unconfigured (so dev + the current keyless deployment
 * keep working); fail-CLOSED when enabled (a missing/invalid token is rejected).
 */
class Turnstile
{
    public static function passes(Request $request): bool
    {
        if (! config('turnstile.enabled') || ! config('turnstile.secret')) {
            return true;   // disabled → not enforced (rate-limit + validation carry it)
        }

        $token = (string) $request->input('cf-turnstile-response', '');
        if ($token === '') {
            return false;
        }

        try {
            $resp = Http::asForm()->timeout(8)->post(config('turnstile.verify_url'), [
                'secret' => config('turnstile.secret'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            return $resp->ok() && $resp->json('success') === true;
        } catch (\Throwable $e) {
            return false;   // enabled but provider unreachable → fail closed
        }
    }
}
