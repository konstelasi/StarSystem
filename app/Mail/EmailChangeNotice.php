<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Goes to the address the account has now, to say a change is waiting, so
 * the real owner hears about it if someone else asked.
 */
class EmailChangeNotice extends Mailable
{
    use EscapesMarkdown;

    public function __construct(
        public readonly string $siteName,
        public readonly string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your email for {$this->siteName} is being changed");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.email-change-notice', with: [
            'site' => $this->plain($this->siteName),
            'email' => $this->plain($this->newEmail),
        ]);
    }
}
