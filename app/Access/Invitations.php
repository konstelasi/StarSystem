<?php

namespace App\Access;

use App\Mail\MailSetup;
use App\Mail\SiteInvitation;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invitations to join the current site.
 *
 * The link's token is shown once and only its sha256 is stored, so the page
 * that creates an invitation is the only place the link exists. Resending
 * makes a new token, which kills the old link.
 */
class Invitations
{
    public const LIFETIME_DAYS = 7;

    public function __construct(private readonly CurrentSite $site) {}

    /**
     * Start an invitation, replacing any unaccepted one for the same address.
     *
     * @return array{invite: SiteInvite, link: string}
     */
    public function issue(string $email, Role $role, User $inviter): array
    {
        $email = mb_strtolower(trim($email));

        SiteInvite::query()->where('email', $email)->whereNull('accepted_at')->delete();

        $token = Str::random(40);

        $invite = SiteInvite::create([
            'email' => $email,
            'role_id' => $role->id,
            'token_hash' => $this->hash($token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return ['invite' => $invite, 'link' => $this->link($token)];
    }

    /**
     * Give an invitation a new link and a fresh week. The old link stops
     * working.
     */
    public function reissue(SiteInvite $invite): string
    {
        $token = Str::random(40);

        $invite->update([
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return $this->link($token);
    }

    /**
     * The invitation a link points at, if it can still be accepted.
     */
    public function find(string $token): ?SiteInvite
    {
        $invite = SiteInvite::query()->where('token_hash', $this->hash($token))->first();

        if ($invite === null || $invite->accepted_at !== null || $invite->expires_at->isPast()) {
            return null;
        }

        return $invite;
    }

    /**
     * Email the link. Returns whether it went out, and never throws, so a
     * broken mail setup still leaves the page able to show the link.
     */
    public function send(SiteInvite $invite, string $link, User $inviter): bool
    {
        if (! $this->mailConfigured()) {
            return false;
        }

        try {
            Mail::to($invite->email)->send(new SiteInvitation(
                siteName: $this->site->get()->name,
                inviterName: $inviter->name,
                roleName: $invite->role->name,
                link: $link,
                expiresAt: $invite->expires_at,
            ));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    public function mailConfigured(): bool
    {
        return MailSetup::configured();
    }

    private function link(string $token): string
    {
        return route('invitations.show', $token);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
