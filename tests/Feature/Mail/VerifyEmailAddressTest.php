<?php

namespace Tests\Feature\Mail;

use App\Mail\VerifyEmailAddress;
use Tests\TestCase;

class VerifyEmailAddressTest extends TestCase
{
    private function mail(string $site = 'Sunrise Bakery', string $email = 'ana@example.com'): VerifyEmailAddress
    {
        return new VerifyEmailAddress($site, $email, 'https://bakery.example/email/verify/7/abc?signature=xyz');
    }

    public function test_it_names_the_site_and_the_address_and_carries_the_link()
    {
        $html = $this->mail()->render();

        $text = strip_tags($html);
        $this->assertStringContainsString('The account on Sunrise Bakery uses ana@example.com', $text);
        $this->assertStringContainsString('The link works for an hour.', $text);
        $this->assertStringContainsString('href="https://bakery.example/email/verify/7/abc?signature=xyz"', $html);
        $this->assertSame('Confirm your email for Sunrise Bakery', $this->mail()->envelope()->subject);
        $this->assertSame(60, VerifyEmailAddress::LIFETIME_MINUTES);
    }

    public function test_a_typed_name_cannot_put_a_link_in_the_mail()
    {
        $html = $this->mail(site: '[Our site](https://evil.example/site) **bold**')->render();

        preg_match_all('/href="([^"]+)"/', $html, $links);
        $this->assertSame([], array_filter($links[1], fn (string $href) => str_contains($href, 'evil.example')));
        $this->assertStringNotContainsString('<strong>bold</strong>', $html);
    }
}
