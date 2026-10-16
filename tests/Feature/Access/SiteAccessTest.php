<?php

namespace Tests\Feature\Access;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class SiteAccessTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    private SiteAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
        $this->access = app(SiteAccess::class);
    }

    private function memberWith(string $role): User
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => Role::builtIn($role)->id]);
        $this->access->forget();

        return $user;
    }

    public function test_the_code_lists_exactly_the_abilities_an_administrator_holds()
    {
        $this->assertEqualsCanonicalizing(Ability::all(), Role::builtIn(Role::ADMINISTRATOR)->abilities());
    }

    public function test_every_ability_is_a_gate()
    {
        $user = $this->memberWith(Role::ADMINISTRATOR);

        foreach (Ability::all() as $ability) {
            $this->assertTrue($user->can($ability), $ability);
        }
    }

    public function test_an_editor_holds_entries_and_media_but_not_the_rest()
    {
        $editor = $this->memberWith(Role::EDITOR);

        $this->assertTrue($editor->can(Ability::ENTRIES_DELETE));
        $this->assertTrue($editor->can(Ability::MEDIA_UPLOAD));
        $this->assertFalse($editor->can(Ability::MODELS_VIEW));
        $this->assertFalse($editor->can(Ability::USERS_VIEW));
        $this->assertFalse($editor->can(Ability::HEALTH_VIEW));
    }

    public function test_a_viewer_can_only_look()
    {
        $viewer = $this->memberWith(Role::VIEWER);

        $this->assertTrue($viewer->can(Ability::ENTRIES_VIEW));
        $this->assertTrue($viewer->can(Ability::MEDIA_VIEW));
        $this->assertFalse($viewer->can(Ability::ENTRIES_CREATE));
        $this->assertFalse($viewer->can(Ability::MEDIA_UPLOAD));
    }

    public function test_a_custom_role_holds_exactly_what_it_was_given()
    {
        $role = Role::create(['tenant_id' => 1, 'name' => 'Proofreader']);
        $role->syncAbilities([Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]);
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        $this->access->forget();

        $this->assertTrue($user->can(Ability::ENTRIES_UPDATE));
        $this->assertFalse($user->can(Ability::ENTRIES_DELETE));
        $this->assertFalse($user->can(Ability::ENTRIES_CREATE));
    }

    public function test_someone_who_is_not_a_member_can_do_nothing()
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();
        $this->access->forget();

        $this->assertFalse($this->access->isMember($user));
        $this->assertNull($this->access->role($user));
        $this->assertSame([], $this->access->abilities($user));
        $this->assertFalse($user->can(Ability::ENTRIES_VIEW));
    }

    public function test_a_membership_of_another_site_gives_nothing_here()
    {
        $other = $this->makeSite('other.example.com');
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();

        $this->asSite($other);
        SiteUser::create(['user_id' => $user->id, 'role_id' => Role::builtIn(Role::ADMINISTRATOR)->id]);
        $this->access->forget();
        $this->assertTrue($user->can(Ability::MODELS_DELETE));

        $this->asSite($this->defaultSite());
        $this->assertFalse($user->can(Ability::MODELS_DELETE));
        $this->assertFalse($this->access->isMember($user));
    }

    public function test_one_account_can_hold_different_roles_on_different_sites()
    {
        $other = $this->makeSite('other.example.com');
        $user = $this->memberWith(Role::VIEWER);

        $this->asSite($other);
        SiteUser::create(['user_id' => $user->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);

        $this->assertTrue($user->can(Ability::ENTRIES_UPDATE));

        $this->asSite($this->defaultSite());
        $this->assertFalse($user->can(Ability::ENTRIES_UPDATE));
    }

    public function test_the_install_owner_can_do_everything_on_every_site_without_a_membership()
    {
        $other = $this->makeSite('other.example.com');
        $owner = User::factory()->installOwner()->create();
        SiteUser::query()->where('user_id', $owner->id)->delete();

        foreach ([$this->defaultSite(), $other] as $site) {
            $this->asSite($site);
            $this->access->forget();

            $this->assertTrue($this->access->isMember($owner));
            $this->assertTrue($owner->can(Ability::ROLES_DELETE));
            $this->assertEqualsCanonicalizing(Ability::all(), $this->access->abilities($owner));
        }
    }

    public function test_only_the_install_owner_manages_modules_and_updates()
    {
        $administrator = $this->memberWith(Role::ADMINISTRATOR);
        $owner = User::factory()->installOwner()->create();

        $this->assertFalse($administrator->can('manage-modules'));
        $this->assertFalse($administrator->can('manage-updates'));
        $this->assertTrue($owner->can('manage-modules'));
        $this->assertTrue($owner->can('manage-updates'));
    }

    public function test_nothing_is_allowed_without_a_current_site()
    {
        $user = $this->memberWith(Role::ADMINISTRATOR);
        app(CurrentSite::class)->forget();

        $this->assertFalse($user->can(Ability::ENTRIES_VIEW));
    }

    public function test_pages_receive_the_role_and_abilities()
    {
        $this->actingAs($this->memberWith(Role::VIEWER))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.installOwner', false)
                ->where('auth.role', 'Viewer')
                ->where('auth.abilities', [Ability::ENTRIES_VIEW, Ability::MEDIA_VIEW])
            );
    }

    public function test_the_install_owner_is_shown_as_owner_with_every_ability()
    {
        $this->actingAs(User::factory()->installOwner()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.installOwner', true)
                ->where('auth.role', 'Owner')
                ->has('auth.abilities', count(Ability::all()))
            );
    }
}
