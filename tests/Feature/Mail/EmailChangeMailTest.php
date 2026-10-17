<?php

namespace Tests\Feature\Mail;

use App\Mail\ConfirmEmailChange;
use App\Mail\EmailChangeNotice;
use Tests\TestCase;

class EmailChangeMailTest extends TestCase
{
    private function confirm(string $site = 'Sunrise Bakery', string $email = 'new@example.com'): ConfirmEmailChange
    {
        return new ConfirmEmailChange($site, $email, 'https://bakery.example/settings/profile/email/7/abc?signature=xyz');
    }

    public function test_the_confirmation_names_the_site_and_the_address_and_carries_the_link()
    {
        $html = $this->confirm()->render();

        $text = strip_tags($html);
        $this->assertStringContainsString('Someone asked to use new@example.com for the account on Sunrise Bakery.', $text);
        $this->assertStringContainsString('The link works for an hour.', $text);
        $this->assertStringContainsString('href="https://bakery.example/settings/profile/email/7/abc?signature=xyz"', $html);
        $this->assertSame('Confirm your new email for Sunrise Bakery', $this->confirm()->envelope()->subject);
        $this->assertSame(60, ConfirmEmailChange::LIFETIME_MINUTES);
    }

    public function test_the_notice_warns_the_current_address_without_a_link_to_confirm()
    {
        $notice = new EmailChangeNotice('Sunrise Bakery', 'new@example.com');
        $html = $notice->render();

        $text = strip_tags($html);
        $this->assertStringContainsString('change the email of your account on Sunrise Bakery to new@example.com', $text);
        $this->assertStringContainsString('change your password now', $text);
        $this->assertSame('Your email for Sunrise Bakery is being changed', $notice->envelope()->subject);

        preg_match_all('/href="([^"]+)"/', $html, $links);
        $this->assertSame([], array_filter($links[1], fn (string $href) => str_contains($href, 'settings/profile')));
    }

    public function test_a_typed_name_cannot_put_a_link_or_formatting_in_either_mail()
    {
        $site = '[Our site](https://evil.example/site) **bold**';

        foreach ([$this->confirm(site: $site), new EmailChangeNotice($site, 'new@example.com')] as $mail) {
            $html = $mail->render();

            preg_match_all('/href="([^"]+)"/', $html, $links);
            $this->assertSame([], array_filter($links[1], fn (string $href) => str_contains($href, 'evil.example')));
            $this->assertStringNotContainsString('<strong>', $html);
            $this->assertStringContainsString('[Our site](https://evil.example/site)', strip_tags($html));
        }
    }

    public function test_an_address_with_markdown_characters_stays_plain_text()
    {
        $html = $this->confirm(email: 'a_b*c@example.com')->render();

        $this->assertStringContainsString('a_b*c@example.com', strip_tags($html));
        $this->assertStringNotContainsString('<em>', $html);
    }
}
