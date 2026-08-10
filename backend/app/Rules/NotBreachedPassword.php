<?php

namespace App\Rules;

use App\Support\PwnedPasswords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a password that appears in the HIBP breach corpus (docs §5.4). Gated
 * by config('auth.breach_check') so it can be turned off (and is off in tests
 * to avoid a network call). The check itself fails open — see PwnedPasswords.
 */
class NotBreachedPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('auth.breach_check', true)) {
            return;
        }
        if (is_string($value) && PwnedPasswords::isBreached($value)) {
            $fail('This password has appeared in a known data breach. Please choose a different one.');
        }
    }
}
