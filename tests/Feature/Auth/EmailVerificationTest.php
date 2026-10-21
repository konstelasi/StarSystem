<?php

namespace Tests\Feature\Auth;

use App\Mail\VerifyEmailAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function realMail(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    private function sentLink(): string
    {
        $link = null;

        Mail::assertSent(VerifyEmailAddress::class, function (VerifyEmailAddress $mail) use (&$link) {
            $link = $mail->link;

            return true;
        });

        return (string) $link;
    }

    public function test_with_working_mail_an_unconfirmed_address_is_sent_to_the_confirm_page()
    {
        $this->realMail();

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_with_working_mail_a_confirmed_address_goes_straight_in()
    {
        $this->realMail();

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }

    public function test_without_mail_nobody_is_asked_to_confirm()
    {
        $this->actingAs(User::factory()->unverified()->create())->get(route('dashboard'))->assertOk();
    }

    public function test_an_address_changed_without_mail_is_asked_for_once_mail_is_set_up()
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->get(route('dashboard'))->assertOk();

        $this->realMail();

        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_the_confirm_page_names_the_address()
    {
        $this->realMail();
        $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/VerifyEmail')
                ->where('email', 'ana@example.com'));
    }

    public function test_an_unconfirmed_account_can_still_reach_its_profile_to_fix_the_address()
    {
        $this->realMail();

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('profile.edit'))
            ->assertOk();
    }

    public function test_asking_again_mails_a_link_to_the_address()
    {
        $this->realMail();
        $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-sent');

        Mail::assertSent(VerifyEmailAddress::class, fn (VerifyEmailAddress $mail) => $mail->hasTo('ana@example.com') && $mail->email === 'ana@example.com');
    }

    public function test_a_confirmed_account_is_sent_no_link()
    {
        $this->realMail();

        $this->actingAs(User::factory()->create())->post(route('verification.send'));

        Mail::assertNothingSent();
    }

    public function test_the_link_confirms_the_address_and_lets_the_account_in()
    {
        $this->realMail();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post(route('verification.send'));

        $this->actingAs($user)->get($this->sentLink())->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_link_for_an_address_the_account_no_longer_has_is_refused()
    {
        $this->realMail();
        $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
        $this->actingAs($user)->post(route('verification.send'));
        $link = $this->sentLink();

        $user->forceFill(['email' => 'new@example.com'])->save();

        $this->actingAs($user)->get($link)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_link_someone_else_holds_confirms_nothing()
    {
        $this->realMail();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post(route('verification.send'));
        $link = $this->sentLink();

        $this->actingAs(User::factory()->unverified()->create())->get($link)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
