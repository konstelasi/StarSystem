<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class UserControllerTest extends TestCase
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

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function role(string $name): int
    {
        return Role::builtIn($name)->id;
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_only_roles_that_may_see_users_open_the_page()
    {
        $this->actingConfirmed($this->memberAs(Role::EDITOR))->get(route('admin.users.index'))->assertForbidden();
        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))->get(route('admin.users.index'))->assertOk();
    }

    public function test_the_page_asks_for_the_password_first()
    {
        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->get(route('admin.users.index'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_it_lists_the_sites_people_with_their_roles()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR, 'Ada');
        $this->memberAs(Role::EDITOR, 'Ben');
        $other = $this->makeSite('other.example.com');
        $outsider = User::factory()->create(['name' => 'Cleo']);
        SiteUser::query()->where('user_id', $outsider->id)->delete();
        $this->asSite($other);
        SiteUser::create(['user_id' => $outsider->id, 'role_id' => $this->role(Role::EDITOR)]);
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('members', 2)
                ->where('members.0.name', 'Ada')
                ->where('members.0.isYou', true)
                ->where('members.0.editable', false)
                ->where('members.1.name', 'Ben')
                ->where('members.1.role.name', 'Editor')
                ->where('members.1.editable', true)
                ->has('roles', 3)
            );
    }

    public function test_the_install_owner_is_listed_even_without_a_membership_and_cannot_be_edited()
    {
        $owner = User::factory()->installOwner()->create(['name' => 'Zed']);
        SiteUser::query()->where('user_id', $owner->id)->delete();

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR, 'Ada'))
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 2)
                ->where('members.1.name', 'Zed')
                ->where('members.1.isOwner', true)
                ->where('members.1.id', null)
                ->where('members.1.editable', false)
            );
    }

    public function test_an_administrator_changes_someones_role()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($admin)
            ->put(route('admin.users.update', $this->membership($ben)->id), ['role_id' => $this->role(Role::VIEWER)])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame($this->role(Role::VIEWER), $this->membership($ben)->role_id);
    }

    public function test_a_role_without_the_ability_cannot_change_roles()
    {
        $editor = $this->memberAs(Role::EDITOR);
        $viewer = $this->memberAs(Role::VIEWER);

        $this->actingConfirmed($editor)
            ->put(route('admin.users.update', $this->membership($viewer)->id), ['role_id' => $this->role(Role::EDITOR)])
            ->assertForbidden();
    }

    public function test_nobody_changes_or_removes_their_own_access()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $membership = $this->membership($admin);

        $this->actingConfirmed($admin)->put(route('admin.users.update', $membership->id), ['role_id' => $this->role(Role::VIEWER)])->assertForbidden();
        $this->actingConfirmed($admin)->delete(route('admin.users.destroy', $membership->id))->assertForbidden();

        $this->assertSame($this->role(Role::ADMINISTRATOR), $membership->fresh()->role_id);
    }

    public function test_the_install_owner_cannot_be_changed_or_removed()
    {
        $owner = User::factory()->installOwner()->create();
        $membership = $this->membership($owner);
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)->put(route('admin.users.update', $membership->id), ['role_id' => $this->role(Role::VIEWER)])->assertForbidden();
        $this->actingConfirmed($admin)->delete(route('admin.users.destroy', $membership->id))->assertForbidden();

        $this->assertNotNull($membership->fresh());
    }

    public function test_nobody_hands_out_a_role_with_abilities_they_lack()
    {
        $role = Role::create(['tenant_id' => 1, 'name' => 'People manager']);
        $role->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE, Ability::USERS_REMOVE, Ability::ENTRIES_VIEW, Ability::MEDIA_VIEW]);
        $manager = $this->memberAs($role);
        $viewer = $this->memberAs(Role::VIEWER);

        // Making someone an administrator would give them what the manager lacks.
        $this->actingConfirmed($manager)
            ->put(route('admin.users.update', $this->membership($viewer)->id), ['role_id' => $this->role(Role::ADMINISTRATOR)])
            ->assertForbidden();

        // A role within the manager's own abilities is fine.
        $this->actingConfirmed($manager)
            ->put(route('admin.users.update', $this->membership($viewer)->id), ['role_id' => $this->role(Role::VIEWER)])
            ->assertRedirect();
    }

    public function test_nobody_changes_or_removes_someone_above_them()
    {
        $role = Role::create(['tenant_id' => 1, 'name' => 'People manager']);
        $role->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE, Ability::USERS_REMOVE]);
        $manager = $this->memberAs($role);
        $editor = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($manager)
            ->put(route('admin.users.update', $this->membership($editor)->id), ['role_id' => $this->role(Role::VIEWER)])
            ->assertForbidden();
        $this->actingConfirmed($manager)
            ->delete(route('admin.users.destroy', $this->membership($editor)->id))
            ->assertForbidden();
    }

    public function test_a_role_from_another_site_is_refused()
    {
        $other = $this->makeSite('other.example.com');
        $foreign = Role::create(['tenant_id' => $other->id, 'name' => 'Foreign']);
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($admin)
            ->put(route('admin.users.update', $this->membership($ben)->id), ['role_id' => $foreign->id])
            ->assertSessionHasErrors('role_id');

        $this->assertSame($this->role(Role::EDITOR), $this->membership($ben)->role_id);
    }

    public function test_removing_someone_ends_only_their_membership_of_this_site()
    {
        $other = $this->makeSite('other.example.com');
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $this->asSite($other);
        SiteUser::create(['user_id' => $ben->id, 'role_id' => $this->role(Role::EDITOR)]);
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($admin)
            ->delete(route('admin.users.destroy', $this->membership($ben)->id))
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame(0, SiteUser::query()->where('user_id', $ben->id)->count());
        $this->assertNotNull($ben->fresh());
        $this->asSite($other);
        $this->assertSame(1, SiteUser::query()->where('user_id', $ben->id)->count());
    }

    public function test_another_sites_membership_cannot_be_reached()
    {
        $other = $this->makeSite('other.example.com');
        $outsider = User::factory()->create();
        SiteUser::query()->where('user_id', $outsider->id)->delete();
        $this->asSite($other);
        $foreign = SiteUser::create(['user_id' => $outsider->id, 'role_id' => $this->role(Role::EDITOR)]);
        $this->asSite($this->defaultSite());

        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)
            ->put(route('admin.users.update', $foreign->id), ['role_id' => $this->role(Role::VIEWER)])
            ->assertNotFound();
        $this->actingConfirmed($admin)->delete(route('admin.users.destroy', $foreign->id))->assertNotFound();
    }
}
