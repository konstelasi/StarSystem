<?php

namespace Tests\Feature\Security;

use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use App\Security\TwoFactorRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class TwoFactorRequirementTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function memberAs(string $role, bool $twoFactor = false): User
    {
        $user = $twoFactor ? User::factory()->withTwoFactor()->create() : User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => Role::builtIn($role)->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    /**
     * @param  list<string>  $roles
     */
    private function require(array $roles): void
    {
        app(TwoFactorRequirement::class)->setRoleIds(array_map(fn (string $name) => Role::builtIn($name)->id, $roles));
    }

    public function test_someone_whose_role_must_use_two_factor_is_sent_to_turn_it_on()
    {
        $this->require([Role::EDITOR]);

        $this->actingAs($this->memberAs(Role::EDITOR))
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_they_can_still_reach_the_pages_that_turn_it_on_and_sign_out()
    {
        $this->require([Role::EDITOR]);
        $editor = $this->memberAs(Role::EDITOR);

        $this->actingAs($editor)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
        $this->actingAs($editor)->get(route('password.confirm'))->assertOk();
        $this->actingAs($editor)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_someone_who_has_turned_it_on_carries_on()
    {
        $this->require([Role::EDITOR]);

        $this->actingAs($this->memberAs(Role::EDITOR, twoFactor: true))->get(route('dashboard'))->assertOk();
    }

    public function test_a_role_that_is_not_on_the_list_is_left_alone()
    {
        $this->require([Role::EDITOR]);

        $this->actingAs($this->memberAs(Role::VIEWER))->get(route('dashboard'))->assertOk();
    }

    public function test_with_no_role_listed_nobody_is_held()
    {
        $this->actingAs($this->memberAs(Role::EDITOR))->get(route('dashboard'))->assertOk();
    }

    public function test_the_install_owner_is_held_as_an_administrator()
    {
        $owner = User::factory()->installOwner()->create();

        $this->require([Role::EDITOR]);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $this->require([Role::ADMINISTRATOR]);
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    }

    public function test_a_request_for_json_gets_a_403_instead_of_a_redirect()
    {
        $this->require([Role::EDITOR]);

        $this->actingAs($this->memberAs(Role::EDITOR))->getJson(route('dashboard'))->assertForbidden();
    }

    public function test_guests_are_not_held_back()
    {
        $this->require([Role::EDITOR, Role::ADMINISTRATOR, Role::VIEWER]);

        $this->get(route('login'))->assertOk();
    }

    public function test_the_requirement_belongs_to_one_site()
    {
        $other = $this->makeSite('other.example.com');
        $this->require([Role::EDITOR]);

        $this->asSite($other);

        $this->assertSame([], app(TwoFactorRequirement::class)->roleIds());
    }

    public function test_stored_junk_is_ignored()
    {
        $site = $this->defaultSite();
        $site->update(['settings' => ['two_factor_roles' => ['3', 3, 'x', null, 4.0]]]);
        $this->asSite($site->fresh());

        $this->assertSame([3, 4], app(TwoFactorRequirement::class)->roleIds());

        $site->update(['settings' => ['two_factor_roles' => 'oops']]);
        $this->asSite($site->fresh());

        $this->assertSame([], app(TwoFactorRequirement::class)->roleIds());
    }

    public function test_saving_the_list_keeps_the_sites_other_settings()
    {
        $site = $this->defaultSite();
        $site->update(['settings' => ['something_else' => true]]);
        $this->asSite($site->fresh());

        app(TwoFactorRequirement::class)->setRoleIds([5, 5, 6]);

        $this->assertSame(['something_else' => true, 'two_factor_roles' => [5, 6]], $this->defaultSite()->settings);
    }
}
