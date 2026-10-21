<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Goes to an account whose address was never confirmed, with the link that
 * confirms it. Sent straight away, not queued, so a failure shows on the page
 * that asked for it.
 */
class VerifyEmailAddress extends Mailable
{
    use EscapesMarkdown;

    /** How long the link works, in minutes. */
    public const LIFETIME_MINUTES = 60;

    public function __construct(
        public readonly string $siteName,
        public readonly string $email,
        public readonly string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Confirm your email for {$this->siteName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.verify-email-address', with: [
            'site' => $this->plain($this->siteName),
            'email' => $this->plain($this->email),
        ]);
    }
}
