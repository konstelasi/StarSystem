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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class UserShowTest extends TestCase
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

    private function open(User $viewer, User $person)
    {
        return $this->actingAs($viewer)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.show', $this->membership($person)->id));
    }

    public function test_the_page_shows_who_they_are_and_what_may_be_done()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR, 'Ben');
        $this->membership($ben)->forceFill(['last_seen_at' => now()->subHour()])->save();

        $this->open($admin, $ben)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Show')
                ->where('member.id', $this->membership($ben)->id)
                ->where('member.userId', $ben->id)
                ->where('member.name', 'Ben')
                ->where('member.email', $ben->email)
                ->where('member.role.name', 'Editor')
                ->where('member.isYou', false)
                ->where('member.isOwner', false)
                ->where('member.twoFactor', false)
                ->where('member.suspended', false)
                ->where('member.editable', true)
                ->where('member.invitedBy', null)
                ->whereNot('member.lastSeenAt', null)
                ->whereNot('member.joinedAt', null)
                ->has('roles', 3)
            );
    }

    public function test_it_says_who_invited_them()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR, 'Ada');
        $ben = $this->memberAs(Role::EDITOR);
        SiteInvite::create([
            'email' => $ben->email,
            'role_id' => Role::builtIn(Role::EDITOR)->id,
            'token_hash' => hash('sha256', 'token'),
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
            'accepted_at' => now(),
        ]);

        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page->where('member.invitedBy', 'Ada'));
    }

    public function test_nothing_can_be_done_to_oneself_or_the_owner_or_a_role_above()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $owner = User::factory()->installOwner()->create();
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Looker']);
        $custom->syncAbilities([Ability::USERS_VIEW]);
        $looker = $this->memberAs($custom);

        $this->open($admin, $admin)->assertInertia(fn (Assert $page) => $page->where('member.isYou', true)->where('member.editable', false));
        $this->open($admin, $owner)->assertInertia(fn (Assert $page) => $page->where('member.isOwner', true)->where('member.editable', false));
        $this->open($looker, $admin)->assertInertia(fn (Assert $page) => $page->where('member.editable', false));
    }

    public function test_the_log_about_them_shows_to_those_who_may_read_it_and_only_them()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $cleo = $this->memberAs(Role::EDITOR);
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Users only']);
        $custom->syncAbilities([Ability::USERS_VIEW]);
        $viewer = $this->memberAs($custom);

        $row = fn (array $over) => (new Activity(['action' => ActivityAction::LOGIN, 'actor_name' => 'x', ...$over]))->save();
        $row(['actor_id' => $ben->id, 'actor_name' => 'Ben', 'action' => ActivityAction::LOGIN]);
        $row(['actor_name' => 'Ada', 'action' => ActivityAction::USER_ROLE_CHANGED, 'subject_type' => 'user', 'subject_id' => $ben->id, 'subject_label' => $ben->email, 'properties' => ['from' => 'Viewer', 'to' => 'Editor']]);
        $row(['actor_id' => $cleo->id, 'actor_name' => 'Cleo']);
        $row(['actor_name' => 'Ada', 'subject_type' => 'user', 'subject_id' => $cleo->id, 'subject_label' => $cleo->email]);

        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page
            ->has('activity', 2)
            ->where('activity.0.label', "Changed someone's role")
            ->where('activity.0.details', ['Viewer to Editor'])
            ->where('activity.1.label', 'Signed in')
        );

        $this->open($viewer, $ben)->assertInertia(fn (Assert $page) => $page->where('activity', null));
    }

    public function test_it_lists_at_most_twenty_rows_newest_first()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        foreach (range(1, 25) as $n) {
            (new Activity(['action' => ActivityAction::LOGIN, 'actor_id' => $ben->id, 'actor_name' => 'Ben', 'subject_label' => "row-{$n}"]))->save();
        }

        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page
            ->has('activity', 20)
            ->where('activity.0.subject', 'row-25')
            ->where('activity.19.subject', 'row-6')
        );
    }

    public function test_devices_counts_live_sessions_where_they_are_kept_in_the_database()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        DB::table('sessions')->insert(['id' => 'phone', 'user_id' => $ben->id, 'ip_address' => '10.0.0.4', 'user_agent' => '', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        DB::table('sessions')->insert(['id' => 'stale', 'user_id' => $ben->id, 'ip_address' => '10.0.0.4', 'user_agent' => '', 'payload' => '', 'last_activity' => now()->subDay()->getTimestamp()]);

        config(['session.driver' => 'database']);
        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page->where('devices', 1));

        config(['session.driver' => 'file']);
        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page->where('devices', null));
    }

    public function test_it_says_when_they_belong_to_another_site_too()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);

        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page->where('member.elsewhere', false));

        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        SiteUser::create(['user_id' => $ben->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());
        app(SiteAccess::class)->forget();

        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page->where('member.elsewhere', true));
    }

    public function test_a_paused_member_shows_as_paused()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $this->membership($ben)->forceFill(['suspended_at' => now()])->save();

        $this->open($admin, $ben)->assertInertia(fn (Assert $page) => $page->where('member.suspended', true)->where('member.editable', true));
    }

    public function test_only_roles_that_may_see_users_open_it()
    {
        $ben = $this->memberAs(Role::EDITOR);

        $this->open($this->memberAs(Role::EDITOR), $ben)->assertForbidden();
    }

    public function test_guests_are_sent_to_sign_in()
    {
        $ben = $this->memberAs(Role::EDITOR);

        $this->get(route('admin.users.show', $this->membership($ben)->id))->assertRedirect(route('login'));
    }

    public function test_the_password_is_asked_for_first()
    {
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->get(route('admin.users.show', $this->membership($ben)->id))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_another_sites_member_cannot_be_opened()
    {
        $other = $this->makeSite('other.example.com');
        $stranger = User::factory()->create();
        SiteUser::query()->where('user_id', $stranger->id)->delete();
        $this->asSite($other);
        $foreign = SiteUser::create(['user_id' => $stranger->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.show', $foreign->id))
            ->assertNotFound();
    }
}
