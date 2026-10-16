<?php

namespace Tests\Feature\Auth;

use App\Access\Invitations;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    private const TOKEN = 'a-long-random-token-for-the-tests-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function invite(string $email = 'ana@example.com', string $role = Role::EDITOR, ?User $by = null): SiteInvite
    {
        return SiteInvite::create([
            'email' => $email,
            'role_id' => Role::builtIn($role)->id,
            'token_hash' => hash('sha256', self::TOKEN),
            'invited_by' => $by?->id,
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function notMember(User $user): User
    {
        SiteUser::query()->where('user_id', $user->id)->delete();
        app(SiteAccess::class)->forget();

        return $user;
    }

    /** @return array<string, string> */
    private function signUp(array $over = []): array
    {
        return ['name' => 'Ana Lima', 'password' => 'A-long-Passw0rd!', 'password_confirmation' => 'A-long-Passw0rd!', ...$over];
    }

    public function test_a_new_email_is_offered_an_account()
    {
        $admin = User::factory()->create(['name' => 'Ada']);

        $this->invite(by: $admin);

        $this->get(route('invitations.show', self::TOKEN))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/AcceptInvitation')
                ->where('mode', 'new')
                ->where('email', 'ana@example.com')
                ->where('role', 'Editor')
                ->where('invitedBy', 'Ada')
                ->where('siteName', $this->defaultSite()->name)
            );
    }

    public function test_accepting_makes_a_verified_account_a_member_and_signs_it_in()
    {
        $invite = $this->invite();

        $this->post(route('invitations.accept', self::TOKEN), $this->signUp())
            ->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'ana@example.com')->sole();
        $this->assertSame('Ana Lima', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_install_owner);
        $this->assertTrue(password_verify('A-long-Passw0rd!', $user->password));
        $this->assertAuthenticatedAs($user);

        $membership = SiteUser::query()->where('user_id', $user->id)->sole();
        $this->assertSame(Role::builtIn(Role::EDITOR)->id, $membership->role_id);
        $this->assertNotNull($invite->fresh()->accepted_at);
    }

    public function test_joining_is_logged_as_the_new_member()
    {
        $this->invite();

        $this->post(route('invitations.accept', self::TOKEN), $this->signUp());

        $user = User::query()->where('email', 'ana@example.com')->sole();
        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::USER_JOINED, $row->action);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame('Ana Lima', $row->actor_name);
        $this->assertSame('ana@example.com', $row->subject_label);
        $this->assertSame(['role' => 'Editor'], $row->properties);
    }

    public function test_a_link_that_does_not_work_logs_nothing()
    {
        $this->get(route('invitations.show', 'a-made-up-link'))->assertStatus(410);
        $this->post(route('invitations.accept', 'a-made-up-link'), $this->signUp())->assertStatus(410);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_the_new_member_can_use_the_site_straight_away()
    {
        $this->invite();
        $this->post(route('invitations.accept', self::TOKEN), $this->signUp());

        $this->get(route('admin.files'))->assertOk();
        $this->get(route('admin.models.index'))->assertForbidden();
    }

    public function test_the_account_needs_a_name_and_a_good_matching_password()
    {
        $this->invite();

        $this->post(route('invitations.accept', self::TOKEN), $this->signUp(['name' => '']))->assertSessionHasErrors('name');
        $this->post(route('invitations.accept', self::TOKEN), $this->signUp(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post(route('invitations.accept', self::TOKEN), $this->signUp(['password_confirmation' => 'different']))->assertSessionHasErrors('password');

        $this->assertFalse(User::query()->where('email', 'ana@example.com')->exists());
        $this->assertGuest();
    }

    public function test_a_link_works_once()
    {
        $this->invite();
        $this->post(route('invitations.accept', self::TOKEN), $this->signUp());
        auth()->logout();

        $this->get(route('invitations.show', self::TOKEN))->assertStatus(410);
        $this->post(route('invitations.accept', self::TOKEN), $this->signUp(['name' => 'Again']))->assertStatus(410);
    }

    public function test_an_expired_or_made_up_link_says_only_that_it_does_not_work()
    {
        $this->invite()->update(['expires_at' => now()->subSecond()]);

        $this->get(route('invitations.show', self::TOKEN))
            ->assertStatus(410)
            ->assertInertia(fn (Assert $page) => $page->component('auth/InvitationInvalid'));
        $this->get(route('invitations.show', 'nothing-like-a-real-token'))
            ->assertStatus(410)
            ->assertInertia(fn (Assert $page) => $page->component('auth/InvitationInvalid'));
        $this->post(route('invitations.accept', self::TOKEN), $this->signUp())->assertStatus(410);
    }

    public function test_a_link_does_not_work_on_another_site()
    {
        $this->invite();
        $other = $this->makeSite('other.example.com');
        config(['starsystem.multisite' => true]);

        $this->get("http://{$other->domains[0]}/invitations/".self::TOKEN)->assertStatus(410);
        $this->post("http://{$other->domains[0]}/invitations/".self::TOKEN, $this->signUp())->assertStatus(410);
        $this->assertFalse(User::query()->where('email', 'ana@example.com')->exists());
    }

    public function test_an_existing_account_signs_in_first_and_then_joins()
    {
        $existing = $this->notMember(User::factory()->create(['email' => 'ana@example.com']));
        $this->invite();

        // The link sends a guest to sign in, and remembers where to return.
        $this->get(route('invitations.show', self::TOKEN))->assertRedirect(route('login'));

        // Signing in is allowed before accepting, and the next page leads back.
        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($existing);
        $this->get(route('dashboard'))->assertRedirect(route('invitations.show', self::TOKEN));

        $this->get(route('invitations.show', self::TOKEN))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/AcceptInvitation')->where('mode', 'join'));

        $this->post(route('invitations.accept', self::TOKEN))->assertRedirect(route('dashboard'));

        $this->assertSame(Role::builtIn(Role::EDITOR)->id, SiteUser::query()->where('user_id', $existing->id)->sole()->role_id);
        $this->assertNotNull(SiteInvite::query()->sole()->accepted_at);
        $this->assertSame(1, User::query()->where('email', 'ana@example.com')->count());
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_an_existing_account_keeps_its_two_factor_step()
    {
        $this->notMember(User::factory()->withTwoFactor()->create(['email' => 'ana@example.com']));
        $this->invite();

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_someone_signed_in_as_another_account_must_sign_out_first()
    {
        User::factory()->create(['email' => 'ana@example.com']);
        $this->invite();

        $this->actingAs(User::factory()->create())
            ->get(route('invitations.show', self::TOKEN))
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'other'));
    }

    public function test_the_wrong_account_cannot_accept()
    {
        $existing = $this->notMember(User::factory()->create(['email' => 'ana@example.com']));
        $this->invite();
        $stranger = $this->notMember(User::factory()->create());

        $this->actingAs($stranger)->post(route('invitations.accept', self::TOKEN))->assertForbidden();
        auth()->logout();
        $this->post(route('invitations.accept', self::TOKEN))->assertForbidden();

        $this->assertSame(0, SiteUser::query()->whereIn('user_id', [$existing->id, $stranger->id])->count());
    }

    public function test_an_account_with_an_invitation_here_who_did_not_use_the_link_is_signed_out()
    {
        $this->notMember(User::factory()->create(['email' => 'ana@example.com']));
        $this->invite();

        // Signed in, but never opened the link in this session.
        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password']);
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_an_account_that_never_confirmed_the_email_cannot_sign_in_on_the_strength_of_an_invitation()
    {
        $this->notMember(User::factory()->unverified()->create(['email' => 'ana@example.com']));
        $this->invite();

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_an_account_that_never_confirmed_the_email_cannot_take_up_the_invitation()
    {
        $unverified = $this->notMember(User::factory()->unverified()->create(['email' => 'ana@example.com']));
        $this->invite();

        $this->actingAs($unverified)
            ->get(route('invitations.show', self::TOKEN))
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'unverified'));

        $this->actingAs($unverified)->post(route('invitations.accept', self::TOKEN))->assertForbidden();

        $this->assertSame(0, SiteUser::query()->where('user_id', $unverified->id)->count());
        $this->assertNull(SiteInvite::query()->sole()->accepted_at);
    }

    public function test_the_link_is_throttled()
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get(route('invitations.show', "guess-{$i}"))->assertStatus(410);
        }

        $this->get(route('invitations.show', 'one-more'))->assertStatus(429);
    }

    public function test_the_link_is_made_from_the_sites_own_address()
    {
        $invite = $this->invite();
        $link = app(Invitations::class)->reissue($invite);

        $this->assertStringStartsWith(url('/invitations/'), $link);
    }
}
