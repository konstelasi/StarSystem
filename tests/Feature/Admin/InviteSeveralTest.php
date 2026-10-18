<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Mail\SiteInvitation;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class InviteSeveralTest extends TestCase
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

    /**
     * @return array<string, mixed> the flashed data of the page the invitation came back to
     */
    private function invite(string $typed, ?User $by = null): array
    {
        $response = $this->actingConfirmed($by ?? $this->memberAs(Role::ADMINISTRATOR))
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->post(route('admin.users.invites.store'), ['email' => $typed, 'role_id' => Role::builtIn(Role::EDITOR)->id]);

        return $response->viewData('page')['flash'] ?? [];
    }

    public function test_several_addresses_each_get_an_invitation_however_they_are_separated()
    {
        $flash = $this->invite("ana@example.com, ben@example.com;\ncleo@example.com dan@example.com");

        $this->assertEqualsCanonicalizing(
            ['ana@example.com', 'ben@example.com', 'cleo@example.com', 'dan@example.com'],
            SiteInvite::query()->pluck('email')->all(),
        );
        $this->assertSame('Invited 4 people.', $flash['toast']['message']);
        $this->assertSame(['Editor'], SiteInvite::query()->with('role')->get()->pluck('role.name')->unique()->values()->all());
    }

    public function test_repeats_and_letter_case_make_one_invitation()
    {
        $this->invite("Ana@Example.com ana@example.com\nANA@EXAMPLE.COM ben@example.com");

        $this->assertEqualsCanonicalizing(['ana@example.com', 'ben@example.com'], SiteInvite::query()->pluck('email')->all());
    }

    public function test_one_address_still_shows_its_own_link()
    {
        $flash = $this->invite('ana@example.com');

        $this->assertSame('ana@example.com', $flash['invite']['email']);
        $this->assertArrayNotHasKey('invites', $flash);
    }

    public function test_with_working_mail_each_is_emailed_and_no_links_are_shown()
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $flash = $this->invite('ana@example.com ben@example.com');

        Mail::assertSent(SiteInvitation::class, 2);
        $this->assertArrayNotHasKey('invites', $flash);
        $this->assertArrayNotHasKey('invite', $flash);
    }

    public function test_without_working_mail_each_link_is_shown_once()
    {
        $flash = $this->invite('ana@example.com ben@example.com');

        $this->assertCount(2, $flash['invites']);
        $this->assertEqualsCanonicalizing(['ana@example.com', 'ben@example.com'], array_column($flash['invites'], 'email'));

        foreach ($flash['invites'] as $shown) {
            $this->assertFalse($shown['emailed']);
            $this->assertFalse($shown['mailConfigured']);
            $this->assertSame(hash('sha256', basename($shown['link'])), SiteInvite::query()->where('email', $shown['email'])->value('token_hash'));
        }
    }

    public function test_a_failed_email_still_shows_its_link_while_the_others_do_not()
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $flash = $this->invite('ana@example.com ben@example.com');

        $this->assertCount(2, $flash['invites']);
        $this->assertTrue($flash['invites'][0]['mailConfigured']);
        $this->assertSame(2, SiteInvite::query()->count());
    }

    public function test_people_who_already_have_access_are_skipped_and_counted()
    {
        $ada = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $owner = User::factory()->installOwner()->create();

        $flash = $this->invite("{$ben->email} {$owner->email} new@example.com", $ada);

        $this->assertSame(['new@example.com'], SiteInvite::query()->pluck('email')->all());
        $this->assertSame('Invited 1 person. 2 already had access.', $flash['toast']['message']);
    }

    public function test_a_list_of_only_people_with_access_is_refused()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $cleo = $this->memberAs(Role::VIEWER);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.store'), ['email' => "{$ben->email}, {$cleo->email}", 'role_id' => Role::builtIn(Role::EDITOR)->id])
            ->assertSessionHasErrors(['email' => 'Everyone listed already has access to the site.']);

        $this->assertSame(0, SiteInvite::query()->count());
    }

    public function test_an_address_that_is_not_one_stops_the_whole_list()
    {
        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com, not-an-email, two@@signs.com', 'role_id' => Role::builtIn(Role::EDITOR)->id])
            ->assertSessionHasErrors(['email' => 'These do not look like email addresses: not-an-email, two@@signs.com.']);

        $this->assertSame(0, SiteInvite::query()->count());
    }

    public function test_one_bad_address_is_named()
    {
        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.invites.store'), ['email' => 'nonsense', 'role_id' => Role::builtIn(Role::EDITOR)->id])
            ->assertSessionHasErrors(['email' => 'nonsense does not look like an email address.']);
    }

    public function test_no_address_and_too_many_are_refused()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $twentyOne = implode(' ', array_map(fn (int $n) => "person{$n}@example.com", range(1, 21)));
        $twenty = implode(' ', array_map(fn (int $n) => "person{$n}@example.com", range(1, 20)));

        $this->actingConfirmed($admin)->post(route('admin.users.invites.store'), ['email' => " \n, ;", 'role_id' => Role::builtIn(Role::EDITOR)->id])->assertSessionHasErrors(['email' => 'Give an email address.']);
        $this->actingConfirmed($admin)->post(route('admin.users.invites.store'), ['email' => $twentyOne, 'role_id' => Role::builtIn(Role::EDITOR)->id])->assertSessionHasErrors(['email' => 'Invite at most 20 people at once.']);
        $this->assertSame(0, SiteInvite::query()->count());

        $this->actingConfirmed($admin)->post(route('admin.users.invites.store'), ['email' => $twenty, 'role_id' => Role::builtIn(Role::EDITOR)->id])->assertSessionHasNoErrors();
        $this->assertSame(20, SiteInvite::query()->count());
    }

    public function test_each_invitation_is_logged_on_its_own()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->invite('ana@example.com ben@example.com', $admin);

        $rows = Activity::query()->orderBy('id')->get();
        $this->assertSame([ActivityAction::INVITE_SENT, ActivityAction::INVITE_SENT], $rows->pluck('action')->all());
        $this->assertEqualsCanonicalizing(['ana@example.com', 'ben@example.com'], $rows->pluck('subject_label')->all());
        $this->assertSame([$admin->id], $rows->pluck('actor_id')->unique()->values()->all());
    }

    public function test_inviting_to_a_role_above_the_actor_is_still_refused_for_a_list()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Inviter']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_INVITE]);

        $this->actingConfirmed($this->memberAs($custom))
            ->post(route('admin.users.invites.store'), ['email' => 'ana@example.com ben@example.com', 'role_id' => Role::builtIn(Role::ADMINISTRATOR)->id])
            ->assertForbidden();

        $this->assertSame(0, SiteInvite::query()->count());
    }
}
