<?php

namespace App\Enums;

/** Account lifecycle state (docs §1.1). */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Invited = 'invited';   // created via invite, no usable password yet
    case PendingVerification = 'pending';   // student registered, email not yet verified (P4)

    public function canSignIn(): bool
    {
        return $this === self::Active;
    }

    /**
     * Student policy (docs §1.5): an unverified student MAY hold a session but
     * is `must_verify`-gated from uploads/submissions (consumed in Phase 5). A
     * suspended account can never sign in.
     */
    public function canStudentSignIn(): bool
    {
        return $this === self::Active || $this === self::PendingVerification;
    }
}
