<?php

namespace Tests\Feature\Access;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class OwnEntriesAbilitiesTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    /**
     * @param  list<string>  $abilities
     */
    private function memberWith(array $abilities): User
    {
        static $n = 0;
        $role = Role::create(['tenant_id' => 1, 'name' => 'Custom '.++$n]);
        $role->syncAbilities($abilities);

        return $this->memberAs($role);
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

    private function access(): SiteAccess
    {
        return app(SiteAccess::class);
    }

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_each_own_form_pairs_with_one_full_ability_and_every_one_exists()
    {
        $this->assertSame(
            ['entries.update' => 'entries.update_own', 'entries.trash' => 'entries.trash_own', 'entries.restore' => 'entries.restore_own', 'entries.delete' => 'entries.delete_own'],
            Ability::OWN,
        );

        foreach (Ability::OWN as $full => $own) {
            $this->assertContains($full, Ability::all());
            $this->assertContains($own, Ability::all());
        }
    }

    public function test_a_route_for_an_ability_is_open_to_a_role_holding_only_its_own_form()
    {
        $contributor = $this->memberWith([Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN]);

        $this->assertTrue(Gate::forUser($contributor)->allows(Ability::ENTRIES_UPDATE));
        $this->assertFalse(Gate::forUser($contributor)->allows(Ability::ENTRIES_TRASH));
        $this->assertFalse(Gate::forUser($contributor)->allows(Ability::ENTRIES_DELETE));
        $this->assertTrue(Gate::forUser($contributor)->allows(Ability::ENTRIES_UPDATE_OWN));
    }

    public function test_the_full_ability_covers_every_entry_whoever_made_it_and_even_none()
    {
        $editor = $this->memberAs(Role::EDITOR);

        foreach ([Ability::ENTRIES_UPDATE, Ability::ENTRIES_TRASH, Ability::ENTRIES_RESTORE, Ability::ENTRIES_DELETE] as $ability) {
            $this->assertTrue($this->access()->mayOnEntry($editor, $ability, $editor->id));
            $this->assertTrue($this->access()->mayOnEntry($editor, $ability, 99999));
            $this->assertTrue($this->access()->mayOnEntry($editor, $ability, null));
        }
    }

    public function test_the_own_form_covers_only_entries_the_account_made()
    {
        $contributor = $this->memberWith([Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN, Ability::ENTRIES_TRASH_OWN]);

        $this->assertTrue($this->access()->mayOnEntry($contributor, Ability::ENTRIES_UPDATE, $contributor->id));
        $this->assertTrue($this->access()->mayOnEntry($contributor, Ability::ENTRIES_TRASH, $contributor->id));
        $this->assertFalse($this->access()->mayOnEntry($contributor, Ability::ENTRIES_UPDATE, $contributor->id + 1));
        $this->assertFalse($this->access()->mayOnEntry($contributor, Ability::ENTRIES_TRASH, $contributor->id + 1));
    }

    public function test_an_entry_with_no_author_is_nobodys_own()
    {
        $contributor = $this->memberWith([Ability::ENTRIES_UPDATE_OWN]);

        $this->assertFalse($this->access()->mayOnEntry($contributor, Ability::ENTRIES_UPDATE, null));
    }

    public function test_an_action_the_role_does_not_hold_in_either_form_is_refused_even_for_ones_own()
    {
        $contributor = $this->memberWith([Ability::ENTRIES_UPDATE_OWN]);

        $this->assertFalse($this->access()->mayOnEntry($contributor, Ability::ENTRIES_DELETE, $contributor->id));
        $this->assertFalse($this->access()->mayOnEntry($contributor, Ability::ENTRIES_RESTORE, $contributor->id));
    }

    public function test_an_ability_with_no_own_form_ignores_authorship()
    {
        $viewer = $this->memberWith([Ability::ENTRIES_VIEW]);

        $this->assertTrue($this->access()->mayOnEntry($viewer, Ability::ENTRIES_VIEW, 99999));
        $this->assertFalse($this->access()->mayOnEntry($viewer, Ability::ENTRIES_CREATE, $viewer->id));
    }

    public function test_the_install_owner_may_do_anything_to_any_entry()
    {
        $owner = User::factory()->installOwner()->create();

        $this->assertTrue($this->access()->mayOnEntry($owner, Ability::ENTRIES_DELETE, null));
    }

    public function test_the_own_form_is_never_the_full_one_in_what_a_page_is_sent()
    {
        $contributor = $this->memberWith([Ability::ENTRIES_UPDATE_OWN]);

        $this->assertSame([Ability::ENTRIES_UPDATE_OWN], $this->access()->abilities($contributor));
    }

    public function test_holding_the_full_ability_counts_as_holding_its_own_form_for_handing_out()
    {
        $full = $this->memberWith([Ability::ENTRIES_UPDATE]);
        $own = $this->memberWith([Ability::ENTRIES_UPDATE_OWN]);

        $this->assertSame([], $this->access()->lacks($full, [Ability::ENTRIES_UPDATE, Ability::ENTRIES_UPDATE_OWN]));
        $this->assertSame([Ability::ENTRIES_UPDATE], $this->access()->lacks($own, [Ability::ENTRIES_UPDATE, Ability::ENTRIES_UPDATE_OWN]));
        $this->assertContains(Ability::ENTRIES_TRASH_OWN, $this->access()->effective($this->memberAs(Role::EDITOR)));
        $this->assertSame([], $this->access()->lacks(User::factory()->installOwner()->create(), Ability::all()));
    }

    public function test_a_role_with_the_own_form_can_be_given_by_someone_with_the_full_one_and_not_the_other_way()
    {
        $withFull = Role::create(['tenant_id' => 1, 'name' => 'Full']);
        $withFull->syncAbilities([Ability::ENTRIES_UPDATE]);
        $withOwn = Role::create(['tenant_id' => 1, 'name' => 'Own']);
        $withOwn->syncAbilities([Ability::ENTRIES_UPDATE_OWN]);

        $fullActor = $this->memberAs($withFull);
        $ownActor = $this->memberAs($withOwn);

        $this->assertTrue($this->access()->canAssign($fullActor, $withOwn));
        $this->assertTrue($this->access()->canAssign($fullActor, $withFull));
        $this->assertTrue($this->access()->canAssign($ownActor, $withOwn));
        $this->assertFalse($this->access()->canAssign($ownActor, $withFull));
    }

    public function test_the_built_in_roles_keep_working_as_they_did()
    {
        $this->assertContains(Ability::ENTRIES_UPDATE, Role::builtIn(Role::EDITOR)->abilities());
        $this->assertNotContains(Ability::ENTRIES_UPDATE_OWN, Role::builtIn(Role::EDITOR)->abilities());
        $this->assertContains(Ability::ENTRIES_UPDATE_OWN, Role::builtIn(Role::ADMINISTRATOR)->abilities());
        $this->assertNotContains(Ability::ENTRIES_UPDATE_OWN, Role::builtIn(Role::VIEWER)->abilities());
    }

    public function test_an_administrator_gives_a_role_the_own_form_and_it_is_saved()
    {
        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.roles.store'), ['name' => 'Contributor', 'abilities' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['entries.update_own', 'entries.view'], Role::query()->where('name', 'Contributor')->sole()->abilities());
    }

    public function test_someone_with_only_the_own_form_cannot_give_the_full_one()
    {
        $actor = $this->memberWith([Ability::ROLES_VIEW, Ability::ROLES_CREATE, Ability::ENTRIES_UPDATE_OWN]);

        $this->actingConfirmed($actor)
            ->post(route('admin.roles.store'), ['name' => 'Sneaky', 'abilities' => [Ability::ENTRIES_UPDATE]])
            ->assertSessionHasErrors('abilities');
        $this->actingConfirmed($actor)
            ->post(route('admin.roles.store'), ['name' => 'Fine', 'abilities' => [Ability::ENTRIES_UPDATE_OWN]])
            ->assertSessionHasNoErrors();
    }

    public function test_someone_with_the_full_one_can_give_the_own_form()
    {
        $actor = $this->memberWith([Ability::ROLES_VIEW, Ability::ROLES_CREATE, Ability::ENTRIES_UPDATE]);

        $this->actingConfirmed($actor)
            ->post(route('admin.roles.store'), ['name' => 'Narrower', 'abilities' => [Ability::ENTRIES_UPDATE_OWN]])
            ->assertSessionHasNoErrors();
    }

    public function test_the_role_form_offers_the_own_forms_as_held_to_those_who_may_give_them()
    {
        $actor = $this->memberWith([Ability::ROLES_VIEW, Ability::ROLES_CREATE, Ability::ENTRIES_UPDATE]);

        $this->actingConfirmed($actor)
            ->get(route('admin.roles.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('areas', fn ($areas) => collect(collect($areas)->firstWhere('name', 'Entries')['abilities'])
                    ->contains(fn ($ability) => $ability['key'] === 'entries.update_own' && $ability['held'] === true))
            );
    }
}
