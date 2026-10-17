<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use App\Security\TwoFactorRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class TwoFactorRequirementControllerTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function memberAs(string|Role $role, bool $twoFactor = false): User
    {
        $user = $twoFactor ? User::factory()->withTwoFactor()->create() : User::factory()->create();
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

    private function id(string $role): int
    {
        return Role::builtIn($role)->id;
    }

    /**
     * @return list<int>
     */
    private function saved(): array
    {
        return app(TwoFactorRequirement::class)->roleIds();
    }

    public function test_an_administrator_saves_which_roles_must_use_two_factor()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR, twoFactor: true);

        $this->actingConfirmed($admin)
            ->put(route('admin.roles.two-factor'), ['roles' => [$this->id(Role::EDITOR), $this->id(Role::VIEWER)]])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertEqualsCanonicalizing([$this->id(Role::EDITOR), $this->id(Role::VIEWER)], $this->saved());
    }

    public function test_an_empty_list_clears_it()
    {
        app(TwoFactorRequirement::class)->setRoleIds([$this->id(Role::EDITOR)]);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR, twoFactor: true))
            ->put(route('admin.roles.two-factor'), ['roles' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->saved());
    }

    public function test_the_list_must_be_sent_even_when_empty()
    {
        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR, twoFactor: true))
            ->put(route('admin.roles.two-factor'), [])
            ->assertSessionHasErrors('roles');
    }

    public function test_it_will_not_cover_the_person_saving_it_before_they_have_two_factor()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)
            ->put(route('admin.roles.two-factor'), ['roles' => [$this->id(Role::ADMINISTRATOR)]])
            ->assertSessionHasErrors('roles');

        $this->assertSame([], $this->saved());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_with_two_factor_on_they_may_cover_their_own_role()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR, twoFactor: true);

        $this->actingConfirmed($admin)
            ->put(route('admin.roles.two-factor'), ['roles' => [$this->id(Role::ADMINISTRATOR)]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->id(Role::ADMINISTRATOR)], $this->saved());
    }

    public function test_a_list_that_leaves_them_out_is_fine_without_two_factor()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)
            ->put(route('admin.roles.two-factor'), ['roles' => [$this->id(Role::EDITOR)]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->id(Role::EDITOR)], $this->saved());
    }

    public function test_the_install_owner_is_covered_by_the_administrator_role()
    {
        $owner = User::factory()->installOwner()->create();

        $this->actingConfirmed($owner)
            ->put(route('admin.roles.two-factor'), ['roles' => [$this->id(Role::ADMINISTRATOR)]])
            ->assertSessionHasErrors('roles');

        $this->assertSame([], $this->saved());
    }

    public function test_only_a_role_that_may_change_roles_can_save_it()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Looker']);
        $custom->syncAbilities([Ability::ROLES_VIEW]);

        $this->actingConfirmed($this->memberAs(Role::EDITOR))->put(route('admin.roles.two-factor'), ['roles' => []])->assertForbidden();
        $this->actingConfirmed($this->memberAs($custom))->put(route('admin.roles.two-factor'), ['roles' => []])->assertForbidden();
    }

    public function test_the_password_is_asked_for_first()
    {
        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->put(route('admin.roles.two-factor'), ['roles' => []])
            ->assertRedirect(route('password.confirm'));
    }

    public function test_unknown_roles_and_another_sites_roles_are_refused()
    {
        $other = $this->makeSite('other.example.com');
        $foreign = Role::create(['tenant_id' => $other->id, 'name' => 'Translator']);
        $admin = $this->memberAs(Role::ADMINISTRATOR, twoFactor: true);

        $this->actingConfirmed($admin)->put(route('admin.roles.two-factor'), ['roles' => [999999]])->assertSessionHasErrors('roles.0');
        $this->actingConfirmed($admin)->put(route('admin.roles.two-factor'), ['roles' => [$foreign->id]])->assertSessionHasErrors('roles.0');

        $this->assertSame([], $this->saved());
    }

    public function test_a_custom_role_of_this_site_can_be_listed()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Proofreader']);
        $admin = $this->memberAs(Role::ADMINISTRATOR, twoFactor: true);

        $this->actingConfirmed($admin)->put(route('admin.roles.two-factor'), ['roles' => [$custom->id]])->assertSessionHasNoErrors();

        $this->assertSame([$custom->id], $this->saved());
    }

    public function test_a_change_is_logged_with_the_role_names_and_a_repeat_is_not()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR, twoFactor: true);
        $payload = ['roles' => [$this->id(Role::EDITOR), $this->id(Role::ADMINISTRATOR)]];

        $this->actingConfirmed($admin)->put(route('admin.roles.two-factor'), $payload);
        $this->actingConfirmed($admin)->put(route('admin.roles.two-factor'), $payload);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::TWO_FACTOR_REQUIREMENT_CHANGED, $row->action);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame(['roles' => ['Administrator', 'Editor']], $row->properties);
    }

    public function test_the_roles_page_carries_the_list_and_what_this_account_may_do()
    {
        app(TwoFactorRequirement::class)->setRoleIds([$this->id(Role::EDITOR)]);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactor.roles', [$this->id(Role::EDITOR)])
                ->where('twoFactor.editable', true)
                ->where('twoFactor.yours', false)
            );

        $viewer = Role::create(['tenant_id' => 1, 'name' => 'Looker']);
        $viewer->syncAbilities([Ability::ROLES_VIEW]);

        $this->actingConfirmed($this->memberAs($viewer, twoFactor: true))
            ->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactor.editable', false)->where('twoFactor.yours', true));
    }
}
