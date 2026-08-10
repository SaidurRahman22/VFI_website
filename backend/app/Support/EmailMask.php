<?php

namespace App\Support;

/**
 * Server-side mirror of js/auth.js `maskEmail` — for display only ("we sent a
 * code to jo•••@example.com"). Keeps the first 1–2 chars of the local part,
 * bullets the rest. Never used for anything but rendering.
 */
class EmailMask
{
    public static function mask(?string $email): string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        $keep = mb_strlen($local) <= 2 ? 1 : 2;
        $head = mb_substr($local, 0, $keep);

        return $head.str_repeat('•', max(1, mb_strlen($local) - $keep)).'@'.$domain;
    }
}
