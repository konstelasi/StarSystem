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
        // The body is markdown, and these names are typed by people, so they
        // are escaped to stay plain text. Otherwise a user named
        // "[Sign in](https://evil.example)" would put a link in an email
        // that comes from this server.
        return new Content(markdown: 'mail.site-invitation', with: [
            'site' => $this->plain($this->siteName),
            'inviter' => $this->plain($this->inviterName),
            'role' => $this->plain($this->roleName),
        ]);
    }

    private function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~=:@&])/', '\\\\$1', $text);
    }
}
