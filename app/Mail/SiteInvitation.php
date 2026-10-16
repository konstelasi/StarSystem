<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email that carries an invitation link. It is sent straight away, not
 * queued, because the queue only runs on StarDust's tick and a failure
 * should show on the page that asked for the invitation.
 */
class SiteInvitation extends Mailable
{
    public function __construct(
        public readonly string $siteName,
        public readonly string $inviterName,
        public readonly string $roleName,
        public readonly string $link,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviterName} invited you to {$this->siteName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.site-invitation');
    }
}
