<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 4D — the password-reset link. The single-use token lives only in this
 * message's URL (and as a sha256 hash at rest). Delivered via the configured
 * mailer (log in dev; Postmark via env once a domain + DKIM exist).
 */
class ResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public int $ttlMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your VFI password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset');
    }
}
