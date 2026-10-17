<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Goes to the address someone asked to move to, with the link that makes the
 * change. Sent straight away, not queued, so a failure shows on the page that
 * asked for it.
 */
class ConfirmEmailChange extends Mailable
{
    use EscapesMarkdown;

    /** How long the link works, in minutes. */
    public const LIFETIME_MINUTES = 60;

    public function __construct(
        public readonly string $siteName,
        public readonly string $newEmail,
        public readonly string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Confirm your new email for {$this->siteName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.confirm-email-change', with: [
            'site' => $this->plain($this->siteName),
            'email' => $this->plain($this->newEmail),
        ]);
    }
}
