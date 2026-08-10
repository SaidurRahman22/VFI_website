<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 6F — the staff review outcome to an applicant: approved, rejected, or
 * more-info. `detail` is the agency name (approved) or the reason/notes.
 */
class PartnerDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $decision,   // approved | rejected | more_info
        public string $detail,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->decision) {
            'approved' => 'Your VFI partner account is approved',
            'rejected' => 'About your VFI partner application',
            default => 'We need a little more information',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'emails.partner-decision');
    }
}
