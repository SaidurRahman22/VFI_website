<?php

namespace App\Enums;

/** Phase 6 — a seat's state within an agency. */
enum MemberStatus: string
{
    case Invited  = 'invited';
    case Active   = 'active';
    case Disabled = 'disabled';

    public function canOperate(): bool
    {
        return $this === self::Active;
    }
}
