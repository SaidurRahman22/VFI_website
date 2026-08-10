<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Breach-list check via the HaveIBeenPwned k-anonymity range API (docs §5.4).
 * Only the first 5 chars of the SHA-1 are ever sent; the full password never
 * leaves the server. Fails OPEN: a network error / non-200 must never block a
 * legitimate signup or reset (availability > this advisory check).
 */
class PwnedPasswords
{
    public static function isBreached(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $resp = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout(3)
                ->get('https://api.pwnedpasswords.com/range/'.$prefix);

            if (! $resp->ok()) {
                return false;   // fail-open
            }

            foreach (preg_split('/\r?\n/', (string) $resp->body()) as $line) {
                [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
                if (strcasecmp($candidate, $suffix) === 0 && (int) $count > 0) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            return false;   // fail-open on any transport error
        }
    }
}
