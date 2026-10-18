<?php

namespace Tests\Feature\Access;

use App\Access\Ability;
use App\Access\AbilityRegistry;
use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use App\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\InteractsWithModules;
use Tests\TestCase;

class ModuleAbilitiesTest extends TestCase
{
    use CrossSite, InteractsWithModules, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
        $this->useTemporaryModulesFolder();
        $this->addFixtureModule('Forms');
    }

    /**
     * Switches the Forms module on the way the next request would find it.
     */
    private function runForms(): void
    {
        app(ModuleManager::class)->enable('forms');
        $this->bootModules();
        app(AbilityRegistry::class)->defineGates();
        app(SiteAccess::class)->forget();
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

    private function custom(string $name, array $abilities): Role
    {
        $role = Role::create(['tenant_id' => 1, 'name' => $name]);
        $role->syncAbilities($abilities);

        return $role;
    }

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_a_running_module_adds_its_abilities_in_a_group_named_after_it()
    {
        $registry = app(AbilityRegistry::class);
        $this->assertNotContains('forms.view', $registry->all());
        $this->assertArrayNotHasKey('Forms', $registry->groups());

        $this->runForms();

        $this->assertSame(['forms.view' => 'See form submissions', 'forms.export' => 'Export form submissions'], $registry->groups()['Forms']);
        $this->assertContains('forms.view', $registry->all());
        $this->assertTrue($registry->exists('forms.export'));
        $this->assertSame('Export form submissions', $registry->label('forms.export'));
        $this->assertSame('forms.gone', $registry->label('forms.gone'));
        $this->assertSame(['entries.view', 'forms.view'], $registry->known(['entries.view', 'forms.view', 'forms.gone']));
        $this->assertSame(array_keys(Ability::groups()), array_slice(array_keys($registry->groups()), 0, count(Ability::groups())));
    }

    public function test_administrator_holds_a_modules_abilities_without_a_migration()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $this->assertNotContains('forms.view', app(SiteAccess::class)->abilities($admin));

        $this->runForms();

        $this->assertContains('forms.view', Role::builtIn(Role::ADMINISTRATOR)->abilities());
        $this->assertContains('forms.export', app(SiteAccess::class)->abilities($admin));
        $this->assertTrue(Gate::forUser($admin)->allows('forms.view'));
        $this->assertSame(0, DB::table('ss_role_abilities')->where('ability', 'like', 'forms.%')->count());
    }

    public function test_the_built_in_roles_that_are_not_administrator_do_not_get_them()
    {
        $this->runForms();

        $this->assertFalse(Gate::forUser($this->memberAs(Role::EDITOR))->allows('forms.view'));
        $this->assertFalse(Gate::forUser($this->memberAs(Role::VIEWER))->allows('forms.view'));
    }

    public function test_the_install_owner_holds_them_too()
    {
        $owner = User::factory()->installOwner()->create();
        $this->runForms();

        $this->assertTrue(Gate::forUser($owner)->allows('forms.export'));
    }

    public function test_a_modules_pages_are_open_to_the_roles_that_hold_its_ability_only()
    {
        $this->runForms();
        $reader = $this->memberAs($this->custom('Reader', ['forms.view']));

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))->get('/_forms-test/submissions')->assertOk()->assertSee('the submissions');
        $this->actingAs($this->memberAs(Role::EDITOR))->get('/_forms-test/submissions')->assertForbidden();
        $this->actingAs($reader)->get('/_forms-test/submissions')->assertOk();
        $this->actingAs($reader)->get('/_forms-test/export')->assertForbidden();
    }

    public function test_the_roles_page_offers_the_modules_abilities_as_their_own_group()
    {
        $this->runForms();
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)
            ->get(route('admin.roles.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('areas.6.name', 'Forms')
                ->where('areas.6.abilities.0.key', 'forms.view')
                ->where('areas.6.abilities.0.label', 'See form submissions')
                ->where('areas.6.abilities.0.held', true)
                ->where('areas.6.abilities.1.key', 'forms.export')
            );
    }

    public function test_an_administrator_gives_a_role_a_modules_ability_and_it_works()
    {
        $this->runForms();
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)
            ->post(route('admin.roles.store'), ['name' => 'Reader', 'abilities' => ['forms.view', Ability::ENTRIES_VIEW]])
            ->assertSessionHasNoErrors();

        $role = Role::query()->where('name', 'Reader')->sole();
        $this->assertSame(['entries.view', 'forms.view'], $role->abilities());
        $this->actingAs($this->memberAs($role))->get('/_forms-test/submissions')->assertOk();
    }

    public function test_nobody_gives_a_modules_ability_they_do_not_hold()
    {
        $this->runForms();
        $editorsRole = $this->custom('Role editor', [Ability::ROLES_VIEW, Ability::ROLES_CREATE, Ability::ENTRIES_VIEW]);

        $this->actingConfirmed($this->memberAs($editorsRole))
            ->post(route('admin.roles.store'), ['name' => 'Sneaky', 'abilities' => ['forms.view']])
            ->assertSessionHasErrors('abilities');

        $this->assertSame(0, Role::query()->where('name', 'Sneaky')->count());
    }

    public function test_an_ability_no_module_declares_is_refused()
    {
        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->post(route('admin.roles.store'), ['name' => 'Odd', 'abilities' => ['forms.view']])
            ->assertSessionHasErrors('abilities.0');
    }

    public function test_when_the_module_is_off_its_abilities_vanish_but_the_roles_keep_them()
    {
        $this->runForms();
        $reader = $this->custom('Reader', ['forms.view', Ability::ENTRIES_VIEW]);
        $member = $this->memberAs($reader);
        $this->assertTrue(Gate::forUser($member)->allows('forms.view'));

        // The next request finds the module switched off.
        app(ModuleManager::class)->disable('forms');
        $this->bootModules();
        app(SiteAccess::class)->forget();

        $this->assertSame(['entries.view'], app(SiteAccess::class)->abilities($member));
        $this->assertSame(['entries.view', 'forms.view'], $reader->abilities());
        $this->assertSame(1, DB::table('ss_role_abilities')->where('role_id', $reader->id)->where('ability', 'forms.view')->count());

        // Switched back on, the role has it again with nobody touching it.
        $this->runForms();
        $this->assertTrue(Gate::forUser($member)->allows('forms.view'));
    }

    public function test_saving_a_role_while_the_module_is_off_does_not_lose_its_abilities()
    {
        $reader = $this->custom('Reader', ['forms.view', Ability::ENTRIES_VIEW]);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))
            ->put(route('admin.roles.update', $reader), ['name' => 'Reader', 'abilities' => [Ability::ENTRIES_VIEW, Ability::MEDIA_VIEW]])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSame(['entries.view', 'forms.view', 'media.view'], $reader->fresh()->abilities());
    }

    public function test_the_role_form_and_the_counts_show_only_what_exists()
    {
        $reader = $this->custom('Reader', ['forms.view', Ability::ENTRIES_VIEW]);
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)->get(route('admin.roles.edit', $reader))
            ->assertInertia(fn (Assert $page) => $page->where('selected', ['entries.view']));
        $this->actingConfirmed($admin)->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles', fn ($roles) => collect($roles)->firstWhere('name', 'Reader')['abilities'] === 1)
            );

        $this->runForms();

        $this->actingConfirmed($admin)->get(route('admin.roles.edit', $reader))
            ->assertInertia(fn (Assert $page) => $page->where('selected', ['entries.view', 'forms.view']));
        $this->actingConfirmed($admin)->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles', fn ($roles) => collect($roles)->firstWhere('name', 'Reader')['abilities'] === 2
                    && collect($roles)->firstWhere('name', 'Administrator')['abilities'] === count(Ability::all()) + 2)
            );
    }

    public function test_a_role_holding_a_switched_off_modules_ability_can_still_be_given_by_someone_without_it()
    {
        $reader = $this->custom('Reader', ['forms.view', Ability::ENTRIES_VIEW]);
        $giver = $this->custom('Giver', [Ability::USERS_VIEW, Ability::USERS_UPDATE, Ability::ENTRIES_VIEW]);

        $this->assertTrue(app(SiteAccess::class)->canAssign($this->memberAs($giver), $reader));

        $this->runForms();
        app(SiteAccess::class)->forget();

        $this->assertFalse(app(SiteAccess::class)->canAssign($this->memberAs($giver), $reader));
    }

    public function test_copying_a_role_copies_every_row_including_a_switched_off_modules()
    {
        $reader = $this->custom('Reader', ['forms.view', Ability::ENTRIES_VIEW]);

        $this->actingConfirmed($this->memberAs(Role::ADMINISTRATOR))->post(route('admin.roles.duplicate', $reader));

        $copy = Role::query()->where('name', 'Copy of Reader')->sole();
        $this->assertSame(['entries.view', 'forms.view'], $copy->abilities());
    }
}
