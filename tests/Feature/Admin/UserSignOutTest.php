<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class UserSignOutTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
        config(['session.driver' => 'database']);
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

    private function addSession(User $user, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.4', 'user_agent' => '', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    }

    /**
     * @return list<string>
     */
    private function sessionsOf(User $user): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
    }

    public function test_an_administrator_signs_a_member_out_of_every_device()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $other = $this->memberAs(Role::EDITOR);
        $this->addSession($ben, 'phone');
        $this->addSession($ben, 'laptop');
        $this->addSession($other, 'theirs');

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->delete(route('admin.users.sessions.destroy', $this->membership($ben)->id))
            ->assertRedirect();

        $this->assertSame([], $this->sessionsOf($ben));
        $this->assertSame(['theirs'], $this->sessionsOf($other));
    }

    public function test_it_is_logged_with_how_many_were_ended_and_nothing_when_there_were_none()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $id = $this->membership($ben)->id;

        $this->actingConfirmed($admin)->delete(route('admin.users.sessions.destroy', $id));
        $this->assertSame(0, Activity::query()->count());

        $this->addSession($ben, 'phone');
        $this->addSession($ben, 'laptop');
        $this->actingConfirmed($admin)->delete(route('admin.users.sessions.destroy', $id));

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::USER_SIGNED_OUT, $row->action);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame($ben->email, $row->subject_label);
        $this->assertSame(['count' => 2], $row->properties);
    }

    public function test_a_member_of_another_site_is_not_signed_out_from_here()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        SiteUser::create(['user_id' => $ben->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());
        $this->addSession($ben, 'phone');

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->delete(route('admin.users.sessions.destroy', $this->membership($ben)->id))
            ->assertRedirect();

        $this->assertSame(['phone'], $this->sessionsOf($ben));
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_when_sessions_are_not_in_the_database_it_says_so_and_changes_nothing()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $this->addSession($ben, 'phone');
        config(['session.driver' => 'file']);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->delete(route('admin.users.sessions.destroy', $this->membership($ben)->id))
            ->assertRedirect();

        $this->assertSame(['phone'], $this->sessionsOf($ben));
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_only_a_role_that_may_sign_people_out_can()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Looker']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE]);
        $ben = $this->memberAs(Role::EDITOR);
        $this->addSession($ben, 'phone');

        $this->actingConfirmed($this->memberAs(Role::EDITOR))->delete(route('admin.users.sessions.destroy', $this->membership($ben)->id))->assertForbidden();
        $this->actingConfirmed($this->memberAs($custom))->delete(route('admin.users.sessions.destroy', $this->membership($ben)->id))->assertForbidden();

        $this->assertSame(['phone'], $this->sessionsOf($ben));
    }

    public function test_nobody_signs_out_themselves_the_install_owner_or_a_role_above_them()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Signer']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_SIGN_OUT]);
        $signer = $this->memberAs($custom);
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $owner = User::factory()->installOwner()->create();
        $this->addSession($admin, 'admin-session');

        $this->actingConfirmed($admin)->delete(route('admin.users.sessions.destroy', $this->membership($admin)->id))->assertForbidden();
        $this->actingConfirmed($admin)->delete(route('admin.users.sessions.destroy', $this->membership($owner)->id))->assertForbidden();
        $this->actingConfirmed($signer)->delete(route('admin.users.sessions.destroy', $this->membership($admin)->id))->assertForbidden();

        $this->assertContains('admin-session', $this->sessionsOf($admin));
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

        $this->actingAs($admin)->delete(route('admin.users.sessions.destroy', $this->membership($ben)->id))->assertRedirect(route('password.confirm'));
        $this->actingConfirmed($admin)->delete(route('admin.users.sessions.destroy', $foreign->id))->assertNotFound();
    }
}
