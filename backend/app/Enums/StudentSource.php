<?php

namespace App\Enums;

/**
 * Phase 7 — how a student entered the system. Drives attribution: only
 * partner_modal / qr_link carry an owning agency; self_signup is unowned until
 * a QR self-registration claims it (docs §3 attribution rule).
 */
enum StudentSource: string
{
    case SelfSignup   = 'self_signup';
    case PartnerModal = 'partner_modal';
    case QrLink       = 'qr_link';
    case Admin        = 'admin';
}
