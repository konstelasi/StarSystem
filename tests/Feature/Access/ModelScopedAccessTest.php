<?php

namespace Tests\Feature\Access;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class ModelScopedAccessTest extends TestCase
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

    private function model(string $slug): SchemaModel
    {
        static $next = 9100;

        return SchemaModel::create(['stardust_model_id' => ++$next, 'slug' => $slug, 'label' => ucfirst($slug)]);
    }

    /**
     * A member whose role holds $global for every model and $scoped for
     * particular ones, as [model slug => abilities].
     *
     * @param  list<string>  $global
     * @param  array<string, list<string>>  $scoped
     */
    private function member(array $global, array $scoped = []): User
    {
        static $n = 0;
        $role = Role::create(['tenant_id' => 1, 'name' => 'Custom '.++$n]);
        $role->syncAbilities($global);
        $role->syncModelAbilities(collect($scoped)->mapWithKeys(fn (array $abilities, string $slug) => [
            SchemaModel::query()->where('slug', $slug)->value('id') => $abilities,
        ])->all());

        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function role(array $global, array $scoped = []): Role
    {
        static $n = 0;
        $role = Role::create(['tenant_id' => 1, 'name' => 'Given '.++$n]);
        $role->syncAbilities($global);
        $role->syncModelAbilities(collect($scoped)->mapWithKeys(fn (array $abilities, string $slug) => [
            SchemaModel::query()->where('slug', $slug)->value('id') => $abilities,
        ])->all());

        return $role;
    }

    private function access(): SiteAccess
    {
        return app(SiteAccess::class);
    }

    public function test_an_ability_held_for_one_model_is_held_for_that_model_only()
    {
        $user = $this->member([], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]]);

        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_UPDATE, $this->posts->id));
        $this->assertFalse($this->access()->allows($user, Ability::ENTRIES_UPDATE, $this->pages->id));
        $this->assertFalse($this->access()->allows($user, Ability::ENTRIES_TRASH, $this->posts->id));
    }

    public function test_asked_about_no_model_it_is_held_if_held_for_any()
    {
        $user = $this->member([], ['posts' => [Ability::ENTRIES_VIEW]]);

        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_VIEW));
        $this->assertFalse($this->access()->allows($user, Ability::ENTRIES_UPDATE));
        $this->assertFalse($this->access()->allows($user, Ability::MEDIA_VIEW));
    }

    public function test_an_ability_held_for_every_model_is_held_for_each()
    {
        $user = $this->member([Ability::ENTRIES_VIEW]);

        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_VIEW, $this->posts->id));
        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_VIEW, $this->pages->id));
        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_VIEW));
    }

    public function test_the_two_add_up()
    {
        $user = $this->member([Ability::ENTRIES_VIEW], ['posts' => [Ability::ENTRIES_UPDATE]]);

        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_VIEW, $this->pages->id));
        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_VIEW, $this->posts->id));
        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_UPDATE, $this->posts->id));
        $this->assertFalse($this->access()->allows($user, Ability::ENTRIES_UPDATE, $this->pages->id));
    }

    public function test_the_list_sent_to_the_page_stays_what_is_held_for_every_model()
    {
        $user = $this->member([Ability::MEDIA_VIEW], ['posts' => [Ability::ENTRIES_VIEW]]);

        $this->assertSame([Ability::MEDIA_VIEW], $this->access()->abilities($user));
        $this->assertSame([$this->posts->id => [Ability::ENTRIES_VIEW]], $this->access()->modelAbilities($user));
    }

    public function test_the_own_form_for_one_model_covers_only_the_entries_made_there_by_the_account()
    {
        $user = $this->member([], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN]]);

        $this->assertTrue($this->access()->allows($user, Ability::ENTRIES_UPDATE, $this->posts->id));
        $this->assertTrue($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, $user->id, $this->posts->id));
        $this->assertFalse($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, $user->id + 1, $this->posts->id));
        $this->assertFalse($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, null, $this->posts->id));
        // The same author on another model gets nothing from it.
        $this->assertFalse($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, $user->id, $this->pages->id));
    }

    public function test_the_full_ability_for_one_model_covers_every_entry_there()
    {
        $user = $this->member([], ['posts' => [Ability::ENTRIES_UPDATE]]);

        $this->assertTrue($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, 999, $this->posts->id));
        $this->assertTrue($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, null, $this->posts->id));
        $this->assertFalse($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, 999, $this->pages->id));
    }

    public function test_without_a_model_an_entry_check_counts_only_what_is_held_for_every_model()
    {
        $user = $this->member([], ['posts' => [Ability::ENTRIES_UPDATE]]);

        $this->assertFalse($this->access()->mayOnEntry($user, Ability::ENTRIES_UPDATE, 999));
    }

    public function test_the_gate_asks_about_the_model_it_is_given()
    {
        $user = $this->member([], ['posts' => [Ability::ENTRIES_VIEW]]);

        $this->assertTrue(Gate::forUser($user)->allows(Ability::ENTRIES_VIEW, $this->posts));
        $this->assertFalse(Gate::forUser($user)->allows(Ability::ENTRIES_VIEW, $this->pages));
        $this->assertTrue(Gate::forUser($user)->allows(Ability::ENTRIES_VIEW));
        $this->assertTrue(Gate::forUser($user)->allows(Ability::ENTRIES_VIEW, (string) $this->posts->id));
        $this->assertFalse(Gate::forUser($user)->allows(Ability::ENTRIES_VIEW, (string) $this->pages->id));
    }

    public function test_the_administrator_and_the_install_owner_hold_everything_for_every_model()
    {
        $admin = User::factory()->create();
        $owner = User::factory()->installOwner()->create();

        foreach ([$admin, $owner] as $user) {
            $this->assertTrue($this->access()->allows($user, Ability::MODELS_UPDATE, $this->pages->id));
            $this->assertTrue($this->access()->mayOnEntry($user, Ability::ENTRIES_DELETE, null, $this->pages->id));
            $this->assertSame([], $this->access()->modelAbilities($user));
        }
    }

    public function test_a_paused_member_holds_nothing_for_any_model()
    {
        $user = $this->member([], ['posts' => [Ability::ENTRIES_VIEW]]);
        SiteUser::query()->where('user_id', $user->id)->update(['suspended_at' => now()]);
        $this->access()->forget();

        $this->assertFalse($this->access()->allows($user, Ability::ENTRIES_VIEW, $this->posts->id));
        $this->assertFalse($this->access()->allows($user, Ability::ENTRIES_VIEW));
    }

    public function test_a_model_ability_that_is_not_scoped_does_nothing_for_a_model()
    {
        $user = $this->member([], ['posts' => [Ability::USERS_INVITE]]);

        $this->assertFalse($this->access()->allows($user, Ability::USERS_INVITE, $this->posts->id));
    }

    public function test_handing_out_counts_what_is_held_for_the_model_named()
    {
        $actor = $this->member([], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]]);

        // For the same model, and the own form of what they hold in full.
        $this->assertTrue($this->access()->canAssign($actor, $this->role([], ['posts' => [Ability::ENTRIES_VIEW]])));
        $this->assertTrue($this->access()->canAssign($actor, $this->role([], ['posts' => [Ability::ENTRIES_UPDATE_OWN]])));
        // Not for another model, nor for every model.
        $this->assertFalse($this->access()->canAssign($actor, $this->role([], ['pages' => [Ability::ENTRIES_VIEW]])));
        $this->assertFalse($this->access()->canAssign($actor, $this->role([Ability::ENTRIES_VIEW])));
        // Not what they don't hold at all.
        $this->assertFalse($this->access()->canAssign($actor, $this->role([], ['posts' => [Ability::ENTRIES_TRASH]])));
    }

    public function test_what_is_held_for_every_model_covers_a_grant_for_any_one()
    {
        $actor = $this->member([Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]);

        $this->assertTrue($this->access()->canAssign($actor, $this->role([], ['posts' => [Ability::ENTRIES_UPDATE]])));
        $this->assertTrue($this->access()->canAssign($actor, $this->role([], ['pages' => [Ability::ENTRIES_UPDATE_OWN]])));
        $this->assertFalse($this->access()->canAssign($actor, $this->role([], ['pages' => [Ability::ENTRIES_DELETE]])));
    }

    public function test_the_install_owner_can_give_anything()
    {
        $owner = User::factory()->installOwner()->create();

        $this->assertTrue($this->access()->canAssign($owner, $this->role([Ability::ENTRIES_DELETE], ['pages' => [Ability::MODELS_UPDATE]])));
    }

    public function test_someone_who_holds_a_role_with_grants_can_be_managed_only_by_those_who_hold_them_too()
    {
        $holder = $this->member([], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]]);
        $manager = $this->member([Ability::USERS_VIEW, Ability::USERS_UPDATE, Ability::ENTRIES_VIEW]);
        $bigger = $this->member([Ability::USERS_VIEW, Ability::USERS_UPDATE, Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]);

        $member = SiteUser::query()->where('user_id', $holder->id)->sole();

        $this->assertFalse($this->access()->mayManage($manager, $member));
        $this->assertTrue($this->access()->mayManage($bigger, $member));
    }
}
