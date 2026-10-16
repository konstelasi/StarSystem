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

class RoleControllerTest extends TestCase
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

    private function admin(): User
    {
        return $this->memberAs(Role::ADMINISTRATOR);
    }

    private function custom(string $name, array $abilities, ?int $site = 1): Role
    {
        $role = Role::create(['tenant_id' => $site, 'name' => $name]);
        $role->syncAbilities($abilities);

        return $role;
    }

    public function test_guests_are_redirected_and_other_roles_are_refused()
    {
        $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
        $this->actingConfirmed($this->memberAs(Role::EDITOR))->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_it_lists_built_in_roles_and_this_sites_own_with_their_people()
    {
        $other = $this->makeSite('other.example.com');
        $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);
        $this->custom('Translator', [Ability::ENTRIES_VIEW], $other->id);
        $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($this->admin())
            ->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/roles/Index')
                ->has('roles', 4)
                ->where('roles.0.name', 'Administrator')
                ->where('roles.0.system', true)
                ->where('roles.0.mine', true)
                ->where('roles.0.editable', false)
                ->where('roles.1.people', 1)
                ->where('roles.1.abilities', 10)
                ->where('roles.3.name', 'Proofreader')
                ->where('roles.3.editable', true)
            );
    }

    public function test_an_administrator_creates_a_role()
    {
        $this->actingConfirmed($this->admin())
            ->post(route('admin.roles.store'), [
                'name' => 'Proofreader',
                'abilities' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE],
            ])
            ->assertRedirect(route('admin.roles.index'));

        $role = Role::query()->where('name', 'Proofreader')->sole();
        $this->assertSame(1, $role->tenant_id);
        $this->assertFalse($role->system);
        $this->assertSame([Ability::ENTRIES_UPDATE, Ability::ENTRIES_VIEW], $role->abilities());
    }

    public function test_creating_a_role_is_logged_with_its_abilities()
    {
        $admin = $this->admin();

        $this->actingConfirmed($admin)->post(route('admin.roles.store'), [
            'name' => 'Proofreader',
            'abilities' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE],
        ]);

        $role = Role::query()->where('name', 'Proofreader')->sole();
        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::ROLE_CREATED, $row->action);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame(['role', $role->id, 'Proofreader'], [$row->subject_type, $row->subject_id, $row->subject_label]);
        $this->assertSame(['abilities' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]], $row->properties);
    }

    public function test_a_role_may_start_with_no_abilities()
    {
        $this->actingConfirmed($this->admin())
            ->post(route('admin.roles.store'), ['name' => 'Guest', 'abilities' => []])
            ->assertRedirect();

        $this->assertSame([], Role::query()->where('name', 'Guest')->sole()->abilities());
    }

    public function test_names_are_unique_within_a_site_and_cannot_borrow_a_built_in_name()
    {
        $this->custom('Proofreader', []);
        $admin = $this->admin();

        $this->actingConfirmed($admin)->post(route('admin.roles.store'), ['name' => 'Proofreader', 'abilities' => []])->assertSessionHasErrors('name');
        $this->actingConfirmed($admin)->post(route('admin.roles.store'), ['name' => 'Editor', 'abilities' => []])->assertSessionHasErrors('name');
    }

    public function test_two_sites_may_use_the_same_name()
    {
        $other = $this->makeSite('other.example.com');
        $this->custom('Proofreader', [], $other->id);

        $this->actingConfirmed($this->admin())
            ->post(route('admin.roles.store'), ['name' => 'Proofreader', 'abilities' => []])
            ->assertSessionHasNoErrors();
    }

    public function test_unknown_abilities_are_refused()
    {
        $this->actingConfirmed($this->admin())
            ->post(route('admin.roles.store'), ['name' => 'Odd', 'abilities' => ['entries.fly']])
            ->assertSessionHasErrors('abilities.0');
    }

    public function test_nobody_gives_an_ability_they_do_not_hold()
    {
        $manager = $this->memberAs($this->custom('Role manager', [
            Ability::ROLES_VIEW, Ability::ROLES_CREATE, Ability::ROLES_UPDATE, Ability::ENTRIES_VIEW,
        ]));

        $this->actingConfirmed($manager)
            ->post(route('admin.roles.store'), ['name' => 'Sneaky', 'abilities' => [Ability::ENTRIES_VIEW, Ability::USERS_UPDATE]])
            ->assertSessionHasErrors('abilities');

        $this->assertFalse(Role::query()->where('name', 'Sneaky')->exists());

        $this->actingConfirmed($manager)
            ->post(route('admin.roles.store'), ['name' => 'Modest', 'abilities' => [Ability::ENTRIES_VIEW]])
            ->assertSessionHasNoErrors();
    }

    public function test_a_custom_role_can_be_edited()
    {
        $role = $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);

        $this->actingConfirmed($this->admin())
            ->put(route('admin.roles.update', $role), ['name' => 'Copy editor', 'abilities' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSame('Copy editor', $role->fresh()->name);
        $this->assertSame([Ability::ENTRIES_UPDATE, Ability::ENTRIES_VIEW], $role->abilities());
    }

    public function test_editing_a_role_logs_the_new_name_and_what_was_added_and_removed()
    {
        $role = $this->custom('Proofreader', [Ability::ENTRIES_VIEW, Ability::MEDIA_VIEW]);

        $this->actingConfirmed($this->admin())
            ->put(route('admin.roles.update', $role), ['name' => 'Copy editor', 'abilities' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]]);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::ROLE_UPDATED, $row->action);
        $this->assertSame('Copy editor', $row->subject_label);
        $this->assertSame([
            'renamed_from' => 'Proofreader',
            'added' => [Ability::ENTRIES_UPDATE],
            'removed' => [Ability::MEDIA_VIEW],
        ], $row->properties);
    }

    public function test_saving_a_role_without_changing_it_logs_nothing()
    {
        $role = $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);

        $this->actingConfirmed($this->admin())
            ->put(route('admin.roles.update', $role), ['name' => 'Proofreader', 'abilities' => [Ability::ENTRIES_VIEW]])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_change_reaches_the_people_who_hold_the_role()
    {
        $role = $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);
        $holder = $this->memberAs($role);
        $this->assertFalse($holder->can(Ability::ENTRIES_UPDATE));

        $this->actingConfirmed($this->admin())
            ->put(route('admin.roles.update', $role), ['name' => 'Proofreader', 'abilities' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]]);

        app(SiteAccess::class)->forget();
        $this->assertTrue($holder->can(Ability::ENTRIES_UPDATE));
    }

    public function test_built_in_roles_are_locked()
    {
        $editor = Role::builtIn(Role::EDITOR);
        $admin = $this->admin();

        $this->actingConfirmed($admin)->put(route('admin.roles.update', $editor), ['name' => 'Editor', 'abilities' => []])->assertForbidden();
        $this->actingConfirmed($admin)->delete(route('admin.roles.destroy', $editor))->assertForbidden();

        $this->assertCount(10, $editor->abilities());
    }

    public function test_the_page_of_a_locked_role_is_read_only()
    {
        $this->actingConfirmed($this->admin())
            ->get(route('admin.roles.edit', Role::builtIn(Role::EDITOR)))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/roles/Form')
                ->where('readonly', true)
                ->where('role.system', true)
                ->has('selected', 10)
            );
    }

    public function test_nobody_edits_the_role_they_hold()
    {
        $role = $this->custom('Role manager', [Ability::ROLES_VIEW, Ability::ROLES_UPDATE]);

        $this->actingConfirmed($this->memberAs($role))
            ->put(route('admin.roles.update', $role), ['name' => 'Role manager', 'abilities' => [Ability::ROLES_VIEW, Ability::ROLES_UPDATE, Ability::USERS_UPDATE]])
            ->assertForbidden();
    }

    public function test_nobody_edits_a_role_with_abilities_they_lack()
    {
        $above = $this->custom('Above', [Ability::USERS_UPDATE, Ability::ENTRIES_VIEW]);
        $manager = $this->memberAs($this->custom('Role manager', [Ability::ROLES_VIEW, Ability::ROLES_UPDATE, Ability::ROLES_DELETE, Ability::ENTRIES_VIEW]));

        $this->actingConfirmed($manager)->put(route('admin.roles.update', $above), ['name' => 'Above', 'abilities' => [Ability::ENTRIES_VIEW]])->assertForbidden();
        $this->actingConfirmed($manager)->delete(route('admin.roles.destroy', $above))->assertForbidden();
    }

    public function test_an_unused_role_can_be_deleted()
    {
        $role = $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);

        $this->actingConfirmed($this->admin())->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));

        $this->assertFalse(Role::query()->whereKey($role->id)->exists());
        $this->assertSame(0, DB::table('ss_role_abilities')->where('role_id', $role->id)->count());
    }

    public function test_deleting_a_role_is_logged_and_a_refused_delete_is_not()
    {
        $unused = $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);
        $held = $this->custom('Translator', [Ability::ENTRIES_VIEW]);
        $this->memberAs($held);
        $admin = $this->admin();

        $this->actingConfirmed($admin)->delete(route('admin.roles.destroy', $held));
        $this->assertSame(0, Activity::query()->count());

        $this->actingConfirmed($admin)->delete(route('admin.roles.destroy', $unused));

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::ROLE_DELETED, $row->action);
        $this->assertSame('Proofreader', $row->subject_label);
    }

    public function test_a_role_someone_holds_cannot_be_deleted()
    {
        $role = $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);
        $this->memberAs($role);

        $this->actingConfirmed($this->admin())->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));

        $this->assertTrue(Role::query()->whereKey($role->id)->exists());
    }

    public function test_a_role_an_invitation_uses_cannot_be_deleted()
    {
        $role = $this->custom('Proofreader', [Ability::ENTRIES_VIEW]);
        SiteInvite::create([
            'email' => 'ana@example.com',
            'role_id' => $role->id,
            'token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingConfirmed($this->admin())->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));

        $this->assertTrue(Role::query()->whereKey($role->id)->exists());
    }

    public function test_a_built_in_role_is_copied_into_a_custom_one()
    {
        $response = $this->actingConfirmed($this->admin())->post(route('admin.roles.duplicate', Role::builtIn(Role::EDITOR)));

        $copy = Role::query()->where('name', 'Copy of Editor')->sole();
        $response->assertRedirect(route('admin.roles.edit', $copy));
        $this->assertSame(1, $copy->tenant_id);
        $this->assertFalse($copy->system);
        $this->assertEqualsCanonicalizing(Role::builtIn(Role::EDITOR)->abilities(), $copy->abilities());
    }

    public function test_copying_a_role_is_logged_with_the_original_name()
    {
        $this->actingConfirmed($this->admin())->post(route('admin.roles.duplicate', Role::builtIn(Role::EDITOR)));

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::ROLE_DUPLICATED, $row->action);
        $this->assertSame('Copy of Editor', $row->subject_label);
        $this->assertSame(['from' => 'Editor'], $row->properties);
    }

    public function test_copying_twice_does_not_clash_on_the_name()
    {
        $admin = $this->admin();
        $editor = Role::builtIn(Role::EDITOR);

        $this->actingConfirmed($admin)->post(route('admin.roles.duplicate', $editor));
        $this->actingConfirmed($admin)->post(route('admin.roles.duplicate', $editor));

        $this->assertTrue(Role::query()->where('name', 'Copy of Editor 2')->exists());
    }

    public function test_nobody_copies_a_role_with_abilities_they_lack()
    {
        $manager = $this->memberAs($this->custom('Role manager', [Ability::ROLES_VIEW, Ability::ROLES_CREATE]));

        $this->actingConfirmed($manager)->post(route('admin.roles.duplicate', Role::builtIn(Role::ADMINISTRATOR)))->assertForbidden();
    }

    public function test_another_sites_role_cannot_be_reached()
    {
        $other = $this->makeSite('other.example.com');
        $foreign = $this->custom('Foreign', [], $other->id);
        $admin = $this->admin();

        $this->actingConfirmed($admin)->get(route('admin.roles.edit', $foreign->id))->assertNotFound();
        $this->actingConfirmed($admin)->put(route('admin.roles.update', $foreign->id), ['name' => 'X', 'abilities' => []])->assertNotFound();
        $this->actingConfirmed($admin)->delete(route('admin.roles.destroy', $foreign->id))->assertNotFound();
    }
}
