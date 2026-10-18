<?php

namespace Tests\Feature\Access;

use App\Access\Ability;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class RoleModelAbilitiesTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function model(string $slug): SchemaModel
    {
        static $next = 9000;

        return SchemaModel::create(['stardust_model_id' => ++$next, 'slug' => $slug, 'label' => ucfirst($slug)]);
    }

    private function custom(string $name = 'Proofreader'): Role
    {
        return Role::create(['tenant_id' => 1, 'name' => $name]);
    }

    public function test_the_abilities_that_can_be_scoped_are_the_entry_ones_and_the_two_model_ones()
    {
        $scoped = Ability::modelScoped();

        $this->assertEqualsCanonicalizing(
            [...array_keys(Ability::groups()['Entries']), 'models.update', 'models.export'],
            $scoped,
        );
        $this->assertContains('entries.update_own', $scoped);
        $this->assertNotContains('models.create', $scoped);
        $this->assertNotContains('users.invite', $scoped);

        foreach ($scoped as $ability) {
            $this->assertContains($ability, Ability::all());
        }
    }

    public function test_a_role_holds_abilities_for_particular_models_and_reads_them_back()
    {
        $posts = $this->model('posts');
        $pages = $this->model('pages');
        $role = $this->custom();

        $role->syncModelAbilities([
            $posts->id => ['entries.view', 'entries.update'],
            $pages->id => ['entries.view'],
        ]);

        $this->assertSame([
            $posts->id => ['entries.update', 'entries.view'],
            $pages->id => ['entries.view'],
        ], $role->modelAbilities());
    }

    public function test_syncing_replaces_what_the_role_held_and_ignores_repeats()
    {
        $posts = $this->model('posts');
        $pages = $this->model('pages');
        $role = $this->custom();
        $role->syncModelAbilities([$posts->id => ['entries.view', 'entries.update']]);

        $role->syncModelAbilities([$pages->id => ['entries.view', 'entries.view']]);

        $this->assertSame([$pages->id => ['entries.view']], $role->modelAbilities());
        $this->assertSame(1, DB::table('ss_role_model_abilities')->where('role_id', $role->id)->count());

        $role->syncModelAbilities([]);

        $this->assertSame([], $role->modelAbilities());
    }

    public function test_it_is_kept_apart_from_what_a_role_holds_for_every_model()
    {
        $posts = $this->model('posts');
        $role = $this->custom();
        $role->syncAbilities(['media.view']);
        $role->syncModelAbilities([$posts->id => ['entries.view']]);

        $this->assertSame(['media.view'], $role->abilities());
        $this->assertSame([$posts->id => ['entries.view']], $role->modelAbilities());
    }

    public function test_the_built_in_roles_hold_none_for_particular_models()
    {
        $this->assertSame([], Role::builtIn(Role::ADMINISTRATOR)->modelAbilities());
        $this->assertSame([], Role::builtIn(Role::EDITOR)->modelAbilities());
        $this->assertSame([], Role::builtIn(Role::VIEWER)->modelAbilities());
    }

    public function test_deleting_a_model_takes_its_grants_with_it_and_leaves_the_others()
    {
        $posts = $this->model('posts');
        $pages = $this->model('pages');
        $role = $this->custom();
        $role->syncModelAbilities([$posts->id => ['entries.view'], $pages->id => ['entries.view']]);

        $posts->delete();

        $this->assertSame([$pages->id => ['entries.view']], $role->modelAbilities());
    }

    public function test_deleting_a_role_takes_its_grants_with_it()
    {
        $posts = $this->model('posts');
        $role = $this->custom();
        $role->syncModelAbilities([$posts->id => ['entries.view']]);

        $role->delete();

        $this->assertSame(0, DB::table('ss_role_model_abilities')->count());
    }

    public function test_the_same_role_cannot_hold_the_same_grant_twice()
    {
        $posts = $this->model('posts');
        $role = $this->custom();
        DB::table('ss_role_model_abilities')->insert(['role_id' => $role->id, 'model_id' => $posts->id, 'ability' => 'entries.view']);

        $this->expectException(QueryException::class);

        DB::table('ss_role_model_abilities')->insert(['role_id' => $role->id, 'model_id' => $posts->id, 'ability' => 'entries.view']);
    }

    public function test_copying_a_role_copies_its_model_grants()
    {
        $posts = $this->model('posts');
        $role = $this->custom('Posts editor');
        $role->syncAbilities(['media.view']);
        $role->syncModelAbilities([$posts->id => ['entries.view', 'entries.update']]);
        $admin = User::factory()->create();
        SiteUser::query()->where('user_id', $admin->id)->update(['role_id' => Role::builtIn(Role::ADMINISTRATOR)->id]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.roles.duplicate', $role));

        $copy = Role::query()->where('name', 'Copy of Posts editor')->sole();
        $this->assertSame(['media.view'], $copy->abilities());
        $this->assertSame([$posts->id => ['entries.update', 'entries.view']], $copy->modelAbilities());
    }
}
