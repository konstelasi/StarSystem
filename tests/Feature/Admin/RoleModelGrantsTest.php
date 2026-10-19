<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityDescriber;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

/**
 * Giving a role abilities for particular models through the roles pages.
 */
class RoleModelGrantsTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    private SchemaModel $posts;

    private SchemaModel $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
        $this->posts = $this->model('posts');
        $this->pages = $this->model('pages');
    }

    private function model(string $slug, string $status = SchemaModel::ACTIVE): SchemaModel
    {
        static $next = 9300;

        return SchemaModel::create(['stardust_model_id' => ++$next, 'slug' => $slug, 'label' => ucfirst($slug), 'status' => $status]);
    }

    private function memberAs(Role $role): User
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function admin(): User
    {
        return $this->memberAs(Role::builtIn(Role::ADMINISTRATOR));
    }

    private function acting(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * @param  list<string>  $abilities
     * @param  array<string, list<string>>  $scoped  By model slug.
     */
    private function custom(string $name, array $abilities, array $scoped = []): Role
    {
        $role = Role::create(['tenant_id' => 1, 'name' => $name]);
        $role->syncAbilities($abilities);
        $role->syncModelAbilities(collect($scoped)->mapWithKeys(fn (array $held, string $slug) => [
            SchemaModel::query()->where('slug', $slug)->value('id') => $held,
        ])->all());

        return $role;
    }

    public function test_a_role_is_created_with_abilities_for_one_model()
    {
        $this->acting($this->admin())
            ->post(route('admin.roles.store'), [
                'name' => 'Blogger',
                'abilities' => [Ability::MEDIA_VIEW],
                'models' => [$this->posts->id => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN]],
            ])
            ->assertRedirect(route('admin.roles.index'));

        $role = Role::query()->where('name', 'Blogger')->sole();
        $this->assertSame([Ability::MEDIA_VIEW], $role->abilities());
        $this->assertSame([$this->posts->id => [Ability::ENTRIES_UPDATE_OWN, Ability::ENTRIES_VIEW]], $role->modelAbilities());
    }

    public function test_a_role_made_without_models_holds_none()
    {
        $this->acting($this->admin())
            ->post(route('admin.roles.store'), ['name' => 'Plain', 'abilities' => [Ability::MEDIA_VIEW]]);

        $this->assertSame([], Role::query()->where('name', 'Plain')->sole()->modelAbilities());
    }

    public function test_saving_replaces_the_grants_and_an_empty_list_clears_them()
    {
        $role = $this->custom('Blogger', [], ['posts' => [Ability::ENTRIES_VIEW]]);
        $admin = $this->admin();

        $this->acting($admin)->put(route('admin.roles.update', $role), [
            'name' => 'Blogger',
            'abilities' => [],
            'models' => [$this->pages->id => [Ability::ENTRIES_VIEW, Ability::ENTRIES_CREATE]],
        ])->assertRedirect(route('admin.roles.index'));

        $this->assertSame([$this->pages->id => [Ability::ENTRIES_CREATE, Ability::ENTRIES_VIEW]], $role->modelAbilities());

        $this->acting($admin)->put(route('admin.roles.update', $role), ['name' => 'Blogger', 'abilities' => [], 'models' => []]);

        $this->assertSame([], $role->modelAbilities());
    }

    public function test_a_request_that_names_no_models_leaves_the_grants_alone()
    {
        $role = $this->custom('Blogger', [], ['posts' => [Ability::ENTRIES_VIEW]]);

        $this->acting($this->admin())
            ->put(route('admin.roles.update', $role), ['name' => 'Writer', 'abilities' => [Ability::MEDIA_VIEW]])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSame([$this->posts->id => [Ability::ENTRIES_VIEW]], $role->modelAbilities());
    }

    public function test_an_unknown_model_or_one_from_another_site_is_refused()
    {
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        $foreign = $this->model('secrets');
        $this->asSite($this->defaultSite());
        $admin = $this->admin();

        foreach ([999999, $foreign->id, 'posts'] as $id) {
            $this->acting($admin)
                ->post(route('admin.roles.store'), ['name' => 'Odd', 'abilities' => [], 'models' => [$id => [Ability::ENTRIES_VIEW]]])
                ->assertSessionHasErrors('models');
        }

        $this->assertFalse(Role::query()->where('name', 'Odd')->exists());
    }

    public function test_an_ability_that_cannot_be_given_per_model_is_refused()
    {
        $this->acting($this->admin())
            ->post(route('admin.roles.store'), ['name' => 'Odd', 'abilities' => [], 'models' => [$this->posts->id => [Ability::USERS_INVITE]]])
            ->assertSessionHasErrors("models.{$this->posts->id}.0");

        $this->assertFalse(Role::query()->where('name', 'Odd')->exists());
    }

    public function test_nobody_gives_a_model_ability_they_do_not_hold_for_that_model()
    {
        $manager = $this->memberAs($this->custom(
            'Role manager',
            [Ability::ROLES_VIEW, Ability::ROLES_CREATE, Ability::ROLES_UPDATE],
            ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]],
        ));

        // What they hold for posts, and the own form of what they hold in full.
        $this->acting($manager)
            ->post(route('admin.roles.store'), ['name' => 'Fine', 'abilities' => [], 'models' => [$this->posts->id => [Ability::ENTRIES_UPDATE, Ability::ENTRIES_DELETE_OWN]]])
            ->assertSessionHasErrors('models');
        $this->acting($manager)
            ->post(route('admin.roles.store'), ['name' => 'Fine', 'abilities' => [], 'models' => [$this->posts->id => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN]]])
            ->assertSessionHasNoErrors();

        // Not for another model, and not what they don't hold at all.
        $this->acting($manager)
            ->post(route('admin.roles.store'), ['name' => 'Too wide', 'abilities' => [], 'models' => [$this->pages->id => [Ability::ENTRIES_VIEW]]])
            ->assertSessionHasErrors('models');
        $this->acting($manager)
            ->post(route('admin.roles.store'), ['name' => 'Too much', 'abilities' => [], 'models' => [$this->posts->id => [Ability::ENTRIES_TRASH]]])
            ->assertSessionHasErrors('models');

        $this->assertSame(['Fine'], Role::query()->whereIn('name', ['Fine', 'Too wide', 'Too much'])->pluck('name')->all());
    }

    public function test_nobody_edits_a_role_with_grants_they_lack()
    {
        $above = $this->custom('Above', [], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_DELETE]]);
        $manager = $this->memberAs($this->custom(
            'Role manager',
            [Ability::ROLES_VIEW, Ability::ROLES_UPDATE, Ability::ROLES_DELETE],
            ['posts' => [Ability::ENTRIES_VIEW]],
        ));

        $this->acting($manager)->put(route('admin.roles.update', $above), ['name' => 'Above', 'abilities' => [], 'models' => []])->assertForbidden();
        $this->acting($manager)->delete(route('admin.roles.destroy', $above))->assertForbidden();
        $this->assertSame([$this->posts->id => [Ability::ENTRIES_DELETE, Ability::ENTRIES_VIEW]], $above->modelAbilities());
    }

    public function test_the_form_lists_active_models_what_the_role_holds_and_what_the_actor_may_give()
    {
        $this->model('gone', SchemaModel::DELETING);
        $role = $this->custom('Blogger', [], ['posts' => [Ability::ENTRIES_VIEW]]);
        $manager = $this->memberAs($this->custom(
            'Role manager',
            [Ability::ROLES_VIEW, Ability::ROLES_UPDATE, Ability::ROLES_CREATE],
            ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]],
        ));

        $this->acting($manager)
            ->get(route('admin.roles.edit', $role))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/roles/Form')
                ->has('models', 2)
                ->where('models.0.label', 'Pages')
                ->where('models.0.held', [])
                ->where('models.1.label', 'Posts')
                ->where('models.1.held', fn ($held) => collect($held)->sort()->values()->all() === [Ability::ENTRIES_UPDATE, Ability::ENTRIES_UPDATE_OWN, Ability::ENTRIES_VIEW])
                ->where('modelSelected', [$this->posts->id => [Ability::ENTRIES_VIEW]])
                ->where('modelRows.0.key', Ability::ENTRIES_VIEW)
                ->where('modelRows.2.key', Ability::ENTRIES_UPDATE)
                ->where('modelRows.2.own.key', Ability::ENTRIES_UPDATE_OWN)
                ->where('modelRows', fn ($rows) => collect($rows)->pluck('key')->contains(Ability::MODELS_EXPORT)
                    && ! collect($rows)->pluck('key')->contains(Ability::ENTRIES_UPDATE_OWN))
            );
    }

    public function test_a_new_role_form_starts_with_no_grants()
    {
        $this->acting($this->admin())
            ->get(route('admin.roles.create'))
            ->assertInertia(fn (Assert $page) => $page->where('modelSelected', []));
    }

    public function test_the_list_counts_the_models_a_role_holds_something_for()
    {
        $this->custom('Blogger', [Ability::MEDIA_VIEW], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE], 'pages' => [Ability::ENTRIES_VIEW]]);
        $this->custom('Plain', [Ability::MEDIA_VIEW]);

        $this->acting($this->admin())
            ->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles.3.name', 'Blogger')
                ->where('roles.3.models', 2)
                ->where('roles.3.abilities', 1)
                ->where('roles.4.name', 'Plain')
                ->where('roles.4.models', 0)
                ->where('roles.0.models', 0)
            );
    }

    public function test_creating_and_changing_grants_is_logged_by_model_name()
    {
        $admin = $this->admin();

        $this->acting($admin)->post(route('admin.roles.store'), [
            'name' => 'Blogger',
            'abilities' => [],
            'models' => [$this->posts->id => [Ability::ENTRIES_VIEW]],
        ]);
        $role = Role::query()->where('name', 'Blogger')->sole();

        $created = Activity::query()->where('action', ActivityAction::ROLE_CREATED)->sole();
        $this->assertSame(['Posts' => ['added' => [Ability::ENTRIES_VIEW]]], $created->properties['models']);
        $this->assertSame(['with no abilities', 'and grants for 1 model'], ActivityDescriber::details($created));

        $this->acting($admin)->put(route('admin.roles.update', $role), [
            'name' => 'Blogger',
            'abilities' => [],
            'models' => [$this->posts->id => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE], $this->pages->id => [Ability::ENTRIES_VIEW]],
        ]);

        $updated = Activity::query()->where('action', ActivityAction::ROLE_UPDATED)->sole();
        $this->assertSame([
            'added Edit entries on Posts',
            'added See entries and open them on Pages',
        ], collect(ActivityDescriber::details($updated))->sort()->values()->all());
    }

    public function test_saving_the_same_grants_again_is_not_logged()
    {
        $role = $this->custom('Blogger', [], ['posts' => [Ability::ENTRIES_VIEW]]);

        $this->acting($this->admin())->put(route('admin.roles.update', $role), [
            'name' => 'Blogger',
            'abilities' => [],
            'models' => [$this->posts->id => [Ability::ENTRIES_VIEW]],
        ]);

        $this->assertSame(0, Activity::query()->where('action', ActivityAction::ROLE_UPDATED)->count());
    }

    public function test_the_describer_words_a_removal_on_a_model()
    {
        $row = new Activity(['action' => ActivityAction::ROLE_UPDATED, 'properties' => ['models' => ['Posts' => ['removed' => [Ability::ENTRIES_DELETE]]]]]);

        $this->assertSame(['removed Delete entries for good on Posts'], ActivityDescriber::details($row));
    }
}
