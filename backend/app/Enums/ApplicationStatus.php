<?php

namespace App\Enums;

/**
 * Phase 7 — the applications pipeline vocabulary (the 8 dashboard KPI cards +
 * the partner-students status select). Status WRITES by staff are Phase 9; P7
 * builds the read pipeline + create. `applications.create` starts at `submitted`.
 */
enum ApplicationStatus: string
{
    case Submitted          = 'submitted';
    case Review             = 'review';
    case Offer              = 'offer';
    case Conditional        = 'conditional';
    case Payment            = 'payment';
    case VisaReceived       = 'visa_received';
    case VisaRejected       = 'visa_rejected';
    case NonEnrolment       = 'non_enrolment';
    case Deferral           = 'deferral';
    case PendingFromPartner = 'pending_from_partner';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
