<?php

namespace Tests\Feature\Settings;

use App\Activity\ActivityAction;
use App\Mail\ConfirmEmailChange;
use App\Mail\EmailChangeNotice;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function realMail(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    private function user(): User
    {
        return User::factory()->create(['email' => 'old@example.com', 'name' => 'Rina']);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function ask(User $user, string $email = 'new@example.com', array $over = [])
    {
        return $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $email,
            'current_password' => 'password',
            ...$over,
        ]);
    }

    private function sentLink(): string
    {
        $link = null;

        Mail::assertSent(ConfirmEmailChange::class, function (ConfirmEmailChange $mail) use (&$link) {
            $link = $mail->link;

            return true;
        });

        return (string) $link;
    }

    public function test_a_new_address_needs_the_current_password()
    {
        $this->realMail();
        $user = $this->user();

        $this->ask($user, over: ['current_password' => 'not-it'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])->assertSessionHasErrors('current_password');

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_a_name_alone_needs_no_password()
    {
        $this->realMail();
        $user = $this->user();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Rina Lima', 'email' => 'old@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Rina Lima', $user->fresh()->name);
    }

    public function test_with_working_mail_the_address_waits_for_its_link()
    {
        $this->realMail();
        $user = $this->user();
        $verified = $user->email_verified_at;

        $this->ask($user)->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('old@example.com', $user->email);
        $this->assertSame('new@example.com', $user->pending_email);
        $this->assertEquals($verified, $user->email_verified_at);

        Mail::assertSent(ConfirmEmailChange::class, fn (ConfirmEmailChange $mail) => $mail->hasTo('new@example.com') && $mail->newEmail === 'new@example.com');
        Mail::assertSent(EmailChangeNotice::class, fn (EmailChangeNotice $mail) => $mail->hasTo('old@example.com') && $mail->newEmail === 'new@example.com');
    }

    public function test_asking_is_logged_but_changes_nothing_yet()
    {
        $this->realMail();
        $user = $this->user();

        $this->ask($user);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::ACCOUNT_EMAIL_REQUESTED, $row->action);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame(['email_to' => 'new@example.com'], $row->properties);
    }

    public function test_the_link_makes_the_change_and_confirms_the_address()
    {
        $this->realMail();
        $user = $this->user();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->ask($user);

        $this->actingAs($user)->get($this->sentLink())->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);

        $row = Activity::query()->where('action', ActivityAction::ACCOUNT_UPDATED)->sole();
        $this->assertEquals(['changed' => ['email'], 'email_from' => 'old@example.com', 'email_to' => 'new@example.com'], $row->properties);
    }

    public function test_a_link_works_once()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user);
        $link = $this->sentLink();

        $this->actingAs($user)->get($link);
        $this->actingAs($user)->get($link)->assertRedirect(route('profile.edit'));

        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertSame(1, Activity::query()->where('action', ActivityAction::ACCOUNT_UPDATED)->count());
    }

    public function test_a_link_is_for_its_own_account_only()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user);
        $link = $this->sentLink();

        $this->actingAs(User::factory()->create())->get($link)->assertForbidden();

        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_a_guest_signs_in_first()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user);
        $link = $this->sentLink();

        auth()->logout();

        $this->get($link)->assertRedirect(route('login'));
    }

    public function test_an_altered_or_expired_link_does_nothing()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user);
        $link = $this->sentLink();

        $this->actingAs($user)->get($link.'x')->assertForbidden();

        $this->travel(ConfirmEmailChange::LIFETIME_MINUTES + 1)->minutes();
        $this->actingAs($user)->get($link)->assertForbidden();

        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_an_older_link_stops_working_when_a_newer_address_is_asked_for()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user, 'first@example.com');
        $first = $this->sentLink();

        $this->ask($user, 'second@example.com');

        $this->actingAs($user)->get($first)->assertRedirect(route('profile.edit'));

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertSame('second@example.com', $user->fresh()->pending_email);
    }

    public function test_an_address_someone_took_in_the_meantime_is_refused()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user);
        $link = $this->sentLink();

        User::factory()->create(['email' => 'new@example.com']);

        $this->actingAs($user)->get($link)->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('old@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_asking_for_the_address_already_held_cancels_a_waiting_change()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'old@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_a_change_in_letter_case_alone_is_no_change()
    {
        $this->realMail();
        $user = $this->user();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'OLD@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_an_address_that_belongs_to_someone_else_is_refused()
    {
        $this->realMail();
        $user = $this->user();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->ask($user, 'taken@example.com')->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_when_the_email_cannot_be_sent_nothing_is_kept()
    {
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));
        $user = $this->user();

        $this->ask($user)->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('old@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertSame(0, Activity::query()->where('action', ActivityAction::ACCOUNT_EMAIL_REQUESTED)->count());
    }

    public function test_without_working_mail_the_address_changes_at_once_and_counts_as_unconfirmed()
    {
        $user = $this->user();

        $this->ask($user)->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_verified_at);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::ACCOUNT_UPDATED, $row->action);
        $this->assertEquals(['changed' => ['email'], 'email_from' => 'old@example.com', 'email_to' => 'new@example.com'], $row->properties);
    }

    public function test_the_profile_page_says_which_address_is_waiting()
    {
        $this->realMail();
        $user = $this->user();
        $this->ask($user);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('pendingEmail', 'new@example.com'));
    }
}
