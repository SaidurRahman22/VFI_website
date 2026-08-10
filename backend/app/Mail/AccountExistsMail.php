<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 4B — sent to an ALREADY-registered address that just tried to register
 * again. This is the enumeration-safe decoy path: the API response is identical
 * to a fresh signup, but the real owner gets a "you already have an account"
 * note (sign in / reset) instead of a verification code.
 */
class AccountExistsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'About your VFI account');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-exists');
    }
}
