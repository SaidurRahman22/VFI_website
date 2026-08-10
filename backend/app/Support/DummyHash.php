<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Enumeration-safety helper (docs Security §Enumeration): when no user row
 * exists we still run a full argon2id verify against a throwaway hash, so
 * "unknown account" and "wrong password" take the same time. The dummy hash is
 * a real argon2id hash minted once per process (not a hand-written constant
 * that might short-circuit the verifier).
 */
class DummyHash
{
    private static ?string $hash = null;

    /** Always returns false, but does the work of a real password check first. */
    public static function verifyAbsent(string $password): bool
    {
        self::$hash ??= Hash::make('absent-user-'.bin2hex(random_bytes(16)));
        Hash::check($password, self::$hash);

        return false;
    }
}
