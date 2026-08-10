<?php

namespace App\Enums;

/** Phase 7 — who caused an append-only application_status_events row. */
enum ActorType: string
{
    case Partner     = 'partner';
    case Staff       = 'staff';
    case System      = 'system';
    case Institution = 'institution';
}
