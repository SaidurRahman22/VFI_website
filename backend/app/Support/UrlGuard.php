<?php

namespace App\Support;

/**
 * URL-scheme allow-list (docs §Security). Permits only http/https/mailto and
 * relative URLs; rejects javascript:, data:, vbscript:, protocol-relative, etc.
 * Used at WRITE time (models) AND READ time (bundle) — defense in depth.
 */
class UrlGuard
{
    public static function safe(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        // relative (path / anchor / query), but not protocol-relative "//host"
        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url) && ! str_starts_with($url, '//')) {
            return $url;
        }
        if (preg_match('#^(https?|mailto):#i', $url)) {
            return $url;
        }

        return '';   // dangerous scheme → dropped
    }
}
