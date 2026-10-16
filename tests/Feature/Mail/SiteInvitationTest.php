<?php

namespace Tests\Feature\Mail;

use App\Mail\SiteInvitation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SiteInvitationTest extends TestCase
{
    private function mail(string $site = 'Sunrise Bakery', string $inviter = 'Ada', string $role = 'Editor'): SiteInvitation
    {
        return new SiteInvitation($site, $inviter, $role, 'https://bakery.example/invitations/token', Carbon::parse('2026-10-23'));
    }

    public function test_it_tells_the_reader_who_invited_them_where_and_as_what()
    {
        $html = $this->mail()->render();

        $this->assertStringContainsString('Ada invited you to Sunrise Bakery as Editor.', strip_tags($html));
        $this->assertStringContainsString('href="https://bakery.example/invitations/token"', $html);
        $this->assertSame('Ada invited you to Sunrise Bakery', $this->mail()->envelope()->subject);
    }

    public function test_a_name_cannot_put_a_link_or_formatting_in_the_email()
    {
        $html = $this->mail(
            site: '[Our site](https://evil.example/site)',
            inviter: '[Sign in here](https://evil.example/phish) or visit https://evil.example/bare',
            role: '**Administrator**',
        )->render();

        // No link leads to the typed address, and the invitation's own is there.
        preg_match_all('/href="([^"]+)"/', $html, $links);
        $this->assertContains('https://bakery.example/invitations/token', $links[1]);
        $this->assertSame([], array_filter($links[1], fn (string $href) => str_contains($href, 'evil.example')));

        $this->assertStringNotContainsString('<strong>', $html);

        // The names still read as the text they were typed as.
        $text = strip_tags($html);
        $this->assertStringContainsString('[Sign in here](https://evil.example/phish)', $text);
        $this->assertStringContainsString('**Administrator**', $text);
    }
}
