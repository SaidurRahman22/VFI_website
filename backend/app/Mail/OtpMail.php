<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 4B — the 6-digit email verification code. The plaintext code lives only
 * in this message (never in the DB). Delivered via the configured mailer (log
 * in dev; Postmark via env once a domain + DKIM exist).
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $ttlMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your VFI verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}
