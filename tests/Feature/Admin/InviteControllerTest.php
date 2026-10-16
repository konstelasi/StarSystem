<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\Invitations;
use App\Access\SiteAccess;
use App\Mail\SiteInvitation;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class InviteControllerTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function memberAs(string|Role $role): User
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update([
            'role_id' => $role instanceof Role ? $role->id : Role::builtIn($role)->id,
        ]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function role(string $name): int
    {
        return Role::builtIn($name)->id;
    }

    private function realMail(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    private function invite(string $email = 'ana@example.com', string $role = Role::EDITOR, string $token = 'a-token'): SiteInvite
    {
        return SiteInvite::create([
            'email' => $email,
            'role_id' => $this->role($role),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_an_invitation_is_stored_hashed_and_expires_in_a_week()
    {
        $this->realMail();
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $response = $this->actingConfirmed($admin)
            ->post(route('admin.users.invites.store'), ['email' => 'Ana@Example.com', 'role_id' => $this->role(Role::EDITOR)])
            ->assertRedirect(route('admin.users.index'));

        $invite = SiteInvite::query()->sole();
        $this->assertSame('ana@example.com', $invite->email);
        $this->assertSame($this->role(Role::EDITOR), $invite->role_id);
        $this->assertSame($admin->id, $invite->invited_by);
        $this->assertTrue($invite->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertNull($invite->accepted_at);

        // Only a hash is kept.
        $this->assertSame(64, strlen($invite->token_hash));
        $response->assertSessionHasNoErrors();
    }

    public function test_the_email_carries_the_link_and_the_page_gets_it_too()
    {
        $this->realMail();

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->followingRedirects()
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com', 'role_id' => $this->role(Role::EDITOR)])
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('invites', 1)
                ->where('invites.0.email', 'ana@example.com')
                ->where('invites.0.role', 'Editor')
                ->where('mailConfigured', true)
            );

        Mail::assertSent(SiteInvitation::class, function (SiteInvitation $mail) {
            $invite = SiteInvite::query()->sole();

            return $mail->hasTo('ana@example.com')
                && str_starts_with($mail->link, url('/invitations/'))
                && hash('sha256', basename($mail->link)) === $invite->token_hash
                && $mail->roleName === 'Editor';
        });
    }

    public function test_without_working_mail_the_link_is_still_made_and_nothing_is_sent()
    {
        Mail::fake();
        $this->assertFalse(app(Invitations::class)->mailConfigured());

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com', 'role_id' => $this->role(Role::EDITOR)])
            ->assertRedirect(route('admin.users.index'));

        Mail::assertNothingSent();
        $this->assertSame(1, SiteInvite::query()->count());
    }

    public function test_a_broken_mail_setup_does_not_lose_the_invitation()
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
        ]);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com', 'role_id' => $this->role(Role::EDITOR)])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame(1, SiteInvite::query()->count());
    }

    public function test_inviting_again_replaces_the_waiting_invitation()
    {
        $this->invite('ana@example.com');
        $first = SiteInvite::query()->sole();

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com', 'role_id' => $this->role(Role::VIEWER)]);

        $now = SiteInvite::query()->sole();
        $this->assertNotSame($first->id, $now->id);
        $this->assertSame($this->role(Role::VIEWER), $now->role_id);
    }

    public function test_someone_who_already_has_access_is_not_invited()
    {
        $member = $this->memberAs(Role::EDITOR);
        $owner = User::factory()->installOwner()->create();
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        foreach ([$member, $owner] as $person) {
            $this->actingConfirmed($admin)
                ->post(route('admin.users.invites.store'), ['email' => $person->email, 'role_id' => $this->role(Role::VIEWER)])
                ->assertSessionHasErrors('email');
        }

        $this->assertSame(0, SiteInvite::query()->count());
    }

    public function test_an_account_that_belongs_only_to_another_site_can_be_invited()
    {
        $other = $this->makeSite('other.example.com');
        $outsider = User::factory()->create();
        SiteUser::query()->where('user_id', $outsider->id)->delete();
        $this->asSite($other);
        SiteUser::create(['user_id' => $outsider->id, 'role_id' => $this->role(Role::EDITOR)]);
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.store'), ['email' => $outsider->email, 'role_id' => $this->role(Role::VIEWER)])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SiteInvite::query()->count());
    }

    public function test_the_email_and_role_are_checked()
    {
        $other = $this->makeSite('other.example.com');
        $foreign = Role::create(['tenant_id' => $other->id, 'name' => 'Foreign']);
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)->post(route('admin.users.invites.store'), ['email' => 'not-an-email', 'role_id' => $this->role(Role::EDITOR)])->assertSessionHasErrors('email');
        $this->actingConfirmed($admin)->post(route('admin.users.invites.store'), ['email' => 'ana@example.com', 'role_id' => $foreign->id])->assertSessionHasErrors('role_id');
        $this->actingConfirmed($admin)->post(route('admin.users.invites.store'), ['email' => 'ana@example.com'])->assertSessionHasErrors('role_id');
    }

    public function test_nobody_invites_to_a_role_with_abilities_they_lack()
    {
        $role = Role::create(['tenant_id' => 1, 'name' => 'Host']);
        $role->syncAbilities([Ability::USERS_VIEW, Ability::USERS_INVITE, Ability::ENTRIES_VIEW]);

        $this->actingConfirmed($this->memberAs($role))
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com', 'role_id' => $this->role(Role::ADMINISTRATOR)])
            ->assertForbidden();
    }

    public function test_only_roles_that_may_invite_can()
    {
        $this->actingConfirmed($this->memberAs(Role::EDITOR))
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com', 'role_id' => $this->role(Role::VIEWER)])
            ->assertForbidden();
    }

    public function test_sending_again_makes_a_new_link_and_kills_the_old_one()
    {
        $this->realMail();
        $invite = $this->invite('ana@example.com', Role::EDITOR, 'old-token');
        $invite->update(['expires_at' => now()->addHour()]);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.resend', $invite->id))
            ->assertRedirect(route('admin.users.index'));

        $invite->refresh();
        $this->assertNotSame(hash('sha256', 'old-token'), $invite->token_hash);
        $this->assertTrue($invite->expires_at->isAfter(now()->addDays(6)));
        Mail::assertSent(SiteInvitation::class);

        $this->get(route('invitations.show', 'old-token'))->assertStatus(410);
    }

    public function test_an_invitation_can_be_withdrawn()
    {
        $invite = $this->invite();

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->delete(route('admin.users.invites.destroy', $invite->id))
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame(0, SiteInvite::query()->count());
        $this->get(route('invitations.show', 'a-token'))->assertStatus(410);
    }

    public function test_nobody_manages_an_invitation_to_a_role_above_them()
    {
        $role = Role::create(['tenant_id' => 1, 'name' => 'Host']);
        $role->syncAbilities([Ability::USERS_VIEW, Ability::USERS_INVITE, Ability::ENTRIES_VIEW]);
        $host = $this->memberAs($role);
        $invite = $this->invite('ana@example.com', Role::ADMINISTRATOR);

        $this->actingConfirmed($host)->post(route('admin.users.invites.resend', $invite->id))->assertForbidden();
        $this->actingConfirmed($host)->delete(route('admin.users.invites.destroy', $invite->id))->assertForbidden();
    }

    public function test_another_sites_invitation_cannot_be_reached()
    {
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        $foreign = $this->invite('ana@example.com');
        $this->asSite($this->defaultSite());

        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)->post(route('admin.users.invites.resend', $foreign->id))->assertNotFound();
        $this->actingConfirmed($admin)->delete(route('admin.users.invites.destroy', $foreign->id))->assertNotFound();
    }

    public function test_the_users_page_lists_only_this_sites_waiting_invitations()
    {
        $other = $this->makeSite('other.example.com');
        $this->invite('here@example.com');
        $this->asSite($other);
        $this->invite('there@example.com', Role::EDITOR, 'other-token');
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('invites', 1)
                ->where('invites.0.email', 'here@example.com')
                ->where('mailConfigured', false)
            );
    }
}
