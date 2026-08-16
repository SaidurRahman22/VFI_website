<?php

namespace App\Enums;

/** Phase 7 — a program request is for a new lead or an existing tenant student. */
enum EnquiryType: string
{
    case NewLead = 'new';
    case Existing = 'existing';
}
