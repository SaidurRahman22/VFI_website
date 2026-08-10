<?php

namespace App\Enums;

/** Account lifecycle state (docs §1.1). */
enum UserStatus: string
{
    case Active    = 'active';
    case Suspended = 'suspended';
    case Invited   = 'invited';   // created via invite, no usable password yet

    public function canSignIn(): bool
    {
        return $this === self::Active;
    }
}
