<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class UserSuspensionTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function memberAs(string|Role $role, ?string $name = null): User
    {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
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

    private function pause(User $user): void
    {
        $this->membership($user)->forceFill(['suspended_at' => now()])->save();
        app(SiteAccess::class)->forget();
    }

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_an_administrator_pauses_a_member_who_keeps_their_role()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR, 'Ben');

        $this->actingConfirmed($admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.suspend', $this->membership($ben)->id))
            ->assertRedirect(route('admin.users.index'));

        $membership = $this->membership($ben);
        $this->assertNotNull($membership->suspended_at);
        $this->assertSame(Role::EDITOR, $membership->role->name);
    }

    public function test_a_paused_member_is_signed_out_on_their_next_request_and_told_why()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $this->pause($ben);

        $this->actingAs($ben)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your access to this site is paused. Ask an administrator.');

        $this->assertGuest();
    }

    public function test_a_json_request_from_a_paused_member_gets_a_403()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $this->pause($ben);

        $this->actingAs($ben)->getJson(route('dashboard'))->assertForbidden();
    }

    public function test_a_paused_member_cannot_sign_in_and_is_told_what_a_wrong_password_is_told()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $this->pause($ben);

        $this->post(route('login.store'), ['email' => $ben->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_an_invitation_does_not_get_a_paused_member_back_in()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $this->pause($ben);
        SiteInvite::create([
            'email' => $ben->email,
            'role_id' => Role::builtIn(Role::EDITOR)->id,
            'token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->post(route('login.store'), ['email' => $ben->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_a_paused_member_holds_no_abilities_and_is_no_member()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $access = app(SiteAccess::class);
        $this->assertTrue($access->isMember($ben));
        $this->assertFalse($access->isSuspended($ben));

        $this->pause($ben);

        $this->assertFalse($access->isMember($ben));
        $this->assertTrue($access->isSuspended($ben));
        $this->assertSame([], $access->abilities($ben));
        $this->assertFalse($access->allows($ben, Ability::ENTRIES_VIEW));
    }

    public function test_resuming_gives_everything_back()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $this->pause($ben);

        $this->actingConfirmed($admin)->delete(route('admin.users.resume', $this->membership($ben)->id))->assertRedirect();

        $this->assertNull($this->membership($ben)->suspended_at);
        $this->actingAs($ben)->get(route('dashboard'))->assertOk();
    }

    public function test_pausing_and_resuming_are_logged_once_each()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $id = $this->membership($ben)->id;

        $this->actingConfirmed($admin)->post(route('admin.users.suspend', $id));
        $this->actingConfirmed($admin)->post(route('admin.users.suspend', $id));
        $this->actingConfirmed($admin)->delete(route('admin.users.resume', $id));
        $this->actingConfirmed($admin)->delete(route('admin.users.resume', $id));

        $rows = Activity::query()->orderBy('id')->get();
        $this->assertSame([ActivityAction::USER_SUSPENDED, ActivityAction::USER_RESUMED], $rows->pluck('action')->all());
        $this->assertSame([$admin->id, $admin->id], $rows->pluck('actor_id')->all());
        $this->assertSame([$ben->email, $ben->email], $rows->pluck('subject_label')->all());
    }

    public function test_only_a_role_that_may_pause_people_can()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Looker']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE]);
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($this->memberAs(Role::EDITOR))->post(route('admin.users.suspend', $this->membership($ben)->id))->assertForbidden();
        $this->actingConfirmed($this->memberAs($custom))->post(route('admin.users.suspend', $this->membership($ben)->id))->assertForbidden();

        $this->assertNull($this->membership($ben)->suspended_at);
    }

    public function test_nobody_pauses_themselves_the_install_owner_or_a_role_above_them()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Pauser']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_SUSPEND]);
        $pauser = $this->memberAs($custom);
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $owner = User::factory()->installOwner()->create();

        $this->actingConfirmed($admin)->post(route('admin.users.suspend', $this->membership($admin)->id))->assertForbidden();
        $this->actingConfirmed($admin)->post(route('admin.users.suspend', $this->membership($owner)->id))->assertForbidden();
        $this->actingConfirmed($pauser)->post(route('admin.users.suspend', $this->membership($admin)->id))->assertForbidden();

        $this->assertNull($this->membership($admin)->suspended_at);
        $this->assertNull($this->membership($owner)->suspended_at);
    }

    public function test_the_install_owner_is_never_paused_even_with_a_marked_row()
    {
        $owner = User::factory()->installOwner()->create();
        $this->membership($owner)->forceFill(['suspended_at' => now()])->save();
        app(SiteAccess::class)->forget();

        $this->assertTrue(app(SiteAccess::class)->isMember($owner));
        $this->assertFalse(app(SiteAccess::class)->isSuspended($owner));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_another_sites_membership_cannot_be_reached()
    {
        $other = $this->makeSite('other.example.com');
        $stranger = User::factory()->create();
        SiteUser::query()->where('user_id', $stranger->id)->delete();
        $this->asSite($other);
        $foreign = SiteUser::create(['user_id' => $stranger->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))->post(route('admin.users.suspend', $foreign->id))->assertNotFound();
    }

    public function test_pausing_asks_for_the_password_first()
    {
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.users.suspend', $this->membership($ben)->id))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_pausing_on_one_site_leaves_the_other_alone()
    {
        $other = $this->makeSite('other.example.com');
        $ben = $this->memberAs(Role::EDITOR);
        $this->asSite($other);
        SiteUser::create(['user_id' => $ben->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());

        $this->pause($ben);

        $this->asSite($other);
        app(SiteAccess::class)->forget();
        $this->assertTrue(app(SiteAccess::class)->isMember($ben));
        $this->assertFalse(app(SiteAccess::class)->isSuspended($ben));
    }
}
