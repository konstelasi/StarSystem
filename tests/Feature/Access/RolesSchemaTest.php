<?php

namespace Tests\Feature\Access;

use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class RolesSchemaTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    public function test_the_three_built_in_roles_are_locked_and_belong_to_no_site()
    {
        $roles = Role::query()->orderBy('id')->get();

        $this->assertSame(['Administrator', 'Editor', 'Viewer'], $roles->pluck('name')->all());
        $this->assertTrue($roles->every(fn (Role $role) => $role->system && $role->tenant_id === null));
    }

    public function test_the_built_in_roles_hold_the_agreed_abilities()
    {
        $administrator = Role::builtIn(Role::ADMINISTRATOR)->abilities();
        $editor = Role::builtIn(Role::EDITOR)->abilities();
        $viewer = Role::builtIn(Role::VIEWER)->abilities();

        $this->assertCount(26, $administrator);
        $this->assertContains('roles.delete', $administrator);
        $this->assertContains('activity.view', $administrator);

        $this->assertCount(10, $editor);
        $this->assertContains('entries.delete', $editor);
        $this->assertContains('media.upload', $editor);
        $this->assertNotContains('models.view', $editor);
        $this->assertNotContains('users.view', $editor);
        $this->assertNotContains('health.view', $editor);
        $this->assertNotContains('activity.view', $editor);

        $this->assertSame(['entries.view', 'media.view'], $viewer);
    }

    public function test_syncing_replaces_a_roles_abilities()
    {
        $role = Role::create(['tenant_id' => 1, 'name' => 'Proofreader']);

        $role->syncAbilities(['entries.view', 'entries.update', 'entries.view']);
        $this->assertSame(['entries.update', 'entries.view'], $role->abilities());

        $role->syncAbilities(['media.view']);
        $this->assertSame(['media.view'], $role->abilities());
    }

    public function test_a_site_sees_the_built_in_roles_and_its_own_only()
    {
        $other = $this->makeSite('other.example.com');
        Role::create(['tenant_id' => 1, 'name' => 'Proofreader']);
        Role::create(['tenant_id' => $other->id, 'name' => 'Translator']);

        $this->asSite($this->defaultSite());
        $this->assertSame(['Administrator', 'Editor', 'Proofreader', 'Viewer'], Role::available()->orderBy('name')->pluck('name')->all());

        $this->asSite($other);
        $this->assertSame(['Administrator', 'Editor', 'Translator', 'Viewer'], Role::available()->orderBy('name')->pluck('name')->all());
    }

    public function test_a_role_name_is_unique_within_a_site()
    {
        Role::create(['tenant_id' => 1, 'name' => 'Proofreader']);

        $this->expectException(QueryException::class);
        Role::create(['tenant_id' => 1, 'name' => 'Proofreader']);
    }

    public function test_the_factory_makes_new_users_administrators_of_the_default_site()
    {
        $user = User::factory()->create();

        $this->asSite($this->defaultSite());

        $this->assertSame('Administrator', SiteUser::query()->where('user_id', $user->id)->sole()->role->name);
        $this->assertFalse($user->fresh()->is_install_owner);
        $this->assertTrue(User::factory()->installOwner()->create()->is_install_owner);
    }

    public function test_memberships_are_invisible_across_sites()
    {
        $user = User::factory()->create();
        $other = $this->makeSite('other.example.com');

        $this->assertInvisibleAcrossSites(
            owner: $other,
            other: $this->defaultSite(),
            write: fn () => SiteUser::create(['user_id' => $user->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]),
            read: fn () => SiteUser::query()->where('role_id', Role::builtIn(Role::EDITOR)->id)->count(),
        );
    }

    public function test_invites_are_invisible_across_sites()
    {
        $other = $this->makeSite('other.example.com');

        $this->assertInvisibleAcrossSites(
            owner: $other,
            other: $this->defaultSite(),
            write: fn () => SiteInvite::create([
                'email' => 'ana@example.com',
                'role_id' => Role::builtIn(Role::EDITOR)->id,
                'token_hash' => hash('sha256', 'token'),
                'expires_at' => now()->addDays(7),
            ]),
            read: fn () => SiteInvite::query()->count(),
        );
    }
}
