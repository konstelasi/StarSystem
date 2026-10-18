<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class PasswordResetLinkTest extends TestCase
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

    private function membership(User $user): SiteUser
    {
        return SiteUser::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function realMail(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
    }

    public function test_with_working_mail_the_link_is_emailed_and_not_shown()
    {
        $this->realMail();
        $ben = $this->memberAs(Role::EDITOR);

        $response = $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->post(route('admin.users.reset-link', $this->membership($ben)->id));

        Notification::assertSentTo($ben, ResetPassword::class);
        $this->assertArrayNotHasKey('reset', $response->viewData('page')['flash'] ?? []);
    }

    public function test_without_working_mail_the_link_is_shown_once_and_it_works()
    {
        Notification::fake();
        $ben = $this->memberAs(Role::EDITOR);

        $response = $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->post(route('admin.users.reset-link', $this->membership($ben)->id));

        Notification::assertNothingSent();

        $reset = $response->viewData('page')['flash']['reset'];
        $this->assertSame($ben->email, $reset['email']);
        $this->assertSame($ben->name, $reset['name']);
        $this->assertSame(config('auth.passwords.users.expire'), $reset['minutes']);
        $this->assertStringStartsWith(url('/reset-password/'), $reset['link']);

        parse_str((string) parse_url($reset['link'], PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($reset['link'], PHP_URL_PATH));
        $this->assertSame($ben->email, $query['email']);
        $this->assertTrue(Password::broker()->tokenExists($ben, $token));

        auth()->logout();
        $this->get($reset['link'])->assertOk();
    }

    public function test_a_failed_email_makes_no_link_and_leaves_no_token_behind()
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        $ben = $this->memberAs(Role::EDITOR);

        $response = $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->post(route('admin.users.reset-link', $this->membership($ben)->id));

        $flash = $response->viewData('page')['flash'];
        $this->assertArrayNotHasKey('reset', $flash);
        $this->assertSame('error', $flash['toast']['type']);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $ben->email)->count());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_without_mail_a_member_of_another_site_gets_no_link_for_anyone_to_pass_on()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        SiteUser::create(['user_id' => $ben->id, 'role_id' => Role::builtIn(Role::ADMINISTRATOR)->id]);
        $this->asSite($this->defaultSite());
        $membership = $this->membership($ben);

        $response = $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->post(route('admin.users.reset-link', $membership->id));

        $flash = $response->viewData('page')['flash'];
        $this->assertArrayNotHasKey('reset', $flash);
        $this->assertSame('error', $flash['toast']['type']);
        $this->assertStringContainsString('another site', $flash['toast']['message']);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $ben->email)->count());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_with_working_mail_a_member_of_another_site_still_gets_the_email()
    {
        $this->realMail();
        $ben = $this->memberAs(Role::EDITOR);
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        SiteUser::create(['user_id' => $ben->id, 'role_id' => Role::builtIn(Role::ADMINISTRATOR)->id]);
        $this->asSite($this->defaultSite());

        $response = $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->from(route('admin.users.index'))
            ->followingRedirects()
            ->post(route('admin.users.reset-link', $this->membership($ben)->id));

        Notification::assertSentTo($ben, ResetPassword::class);
        $this->assertArrayNotHasKey('reset', $response->viewData('page')['flash'] ?? []);
    }

    public function test_it_is_logged_with_whether_it_was_emailed_and_never_with_the_link()
    {
        $this->realMail();
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($admin)->post(route('admin.users.reset-link', $this->membership($ben)->id));

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::USER_RESET_LINK, $row->action);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame($ben->email, $row->subject_label);
        $this->assertSame(['emailed' => true], $row->properties);
    }

    public function test_only_a_role_that_may_send_reset_links_can()
    {
        $this->realMail();
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Looker']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE]);
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($this->memberAs(Role::EDITOR))->post(route('admin.users.reset-link', $this->membership($ben)->id))->assertForbidden();
        $this->actingConfirmed($this->memberAs($custom))->post(route('admin.users.reset-link', $this->membership($ben)->id))->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_nobody_makes_a_link_for_themselves_the_install_owner_or_a_role_above_them()
    {
        $this->realMail();
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Linker']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_RESET_PASSWORD]);
        $linker = $this->memberAs($custom);
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $owner = User::factory()->installOwner()->create();

        $this->actingConfirmed($admin)->post(route('admin.users.reset-link', $this->membership($admin)->id))->assertForbidden();
        $this->actingConfirmed($admin)->post(route('admin.users.reset-link', $this->membership($owner)->id))->assertForbidden();
        $this->actingConfirmed($linker)->post(route('admin.users.reset-link', $this->membership($admin)->id))->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_another_sites_membership_cannot_be_reached_and_the_password_is_asked_for()
    {
        $other = $this->makeSite('other.example.com');
        $stranger = User::factory()->create();
        SiteUser::query()->where('user_id', $stranger->id)->delete();
        $this->asSite($other);
        $foreign = SiteUser::create(['user_id' => $stranger->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingAs($admin)->post(route('admin.users.reset-link', $this->membership($ben)->id))->assertRedirect(route('password.confirm'));
        $this->actingConfirmed($admin)->post(route('admin.users.reset-link', $foreign->id))->assertNotFound();
    }
}
