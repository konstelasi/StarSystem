<?php

namespace Tests\Feature\Access;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

/**
 * What each role may reach, on the routes themselves. Which abilities a
 * role holds is covered by SiteAccessTest.
 */
class RouteAccessTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function as(string $role): static
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => Role::builtIn($role)->id]);
        app(SiteAccess::class)->forget();

        return $this->actingAs($user);
    }

    private function model(): SchemaModel
    {
        return SchemaModel::create(['stardust_model_id' => 987654, 'slug' => 'articles', 'label' => 'Articles']);
    }

    public function test_a_non_member_cannot_sign_in_and_is_told_what_a_wrong_password_is_told()
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $refusal = session('errors')->first('email');

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertSame($refusal, session('errors')->first('email'));
    }

    public function test_the_install_owner_signs_in_without_a_membership()
    {
        $owner = User::factory()->installOwner()->create();
        SiteUser::query()->where('user_id', $owner->id)->delete();

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($owner);
    }

    public function test_someone_invited_here_can_sign_in_before_accepting()
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();
        SiteInvite::create([
            'email' => $user->email,
            'role_id' => Role::builtIn(Role::EDITOR)->id,
            'token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_expired_invitation_does_not_let_anyone_in()
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();
        SiteInvite::create([
            'email' => $user->email,
            'role_id' => Role::builtIn(Role::EDITOR)->id,
            'token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_someone_removed_while_signed_in_is_signed_out_on_the_next_request()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        SiteUser::query()->where('user_id', $user->id)->delete();
        app(SiteAccess::class)->forget();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_removed_member_gets_a_403_from_the_json_api_instead()
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();

        $this->actingAs($user)->getJson(route('admin.api.files.index'))->assertForbidden();
    }

    public function test_any_member_reaches_the_dashboard()
    {
        $this->as(Role::VIEWER)->get(route('dashboard'))->assertOk();
    }

    public function test_a_viewer_can_look_but_not_change_or_build()
    {
        $model = $this->model();

        $this->as(Role::VIEWER);

        $this->get(route('admin.files'))->assertOk();
        $this->getJson(route('admin.api.files.index'))->assertOk();

        $this->postJson(route('admin.api.files.store'))->assertForbidden();
        $this->patchJson(route('admin.api.files.update', 'a'))->assertForbidden();
        $this->deleteJson(route('admin.api.files.destroy', 'a'))->assertForbidden();

        $this->get(route('admin.models.entries.create', $model->id))->assertForbidden();
        $this->post(route('admin.models.entries.store', $model->id))->assertForbidden();
        $this->put(route('admin.models.entries.update', [$model->id, 1]))->assertForbidden();
        $this->post(route('admin.models.entries.trash.store', [$model->id, 1]))->assertForbidden();
        $this->post(route('admin.models.entries.restore', [$model->id, 1]))->assertForbidden();
        $this->delete(route('admin.models.entries.destroy', [$model->id, 1]))->assertForbidden();

        $this->get(route('admin.models.index'))->assertForbidden();
        $this->get(route('admin.health'))->assertForbidden();
    }

    public function test_an_editor_works_on_entries_and_media_but_does_not_build_or_manage()
    {
        $model = $this->model();

        $this->as(Role::EDITOR);

        $this->get(route('admin.files'))->assertOk();

        $this->get(route('admin.models.index'))->assertForbidden();
        $this->post(route('admin.models.store'))->assertForbidden();
        $this->get(route('admin.models.builder', $model->id))->assertForbidden();
        $this->post(route('admin.models.builder.save', $model->id))->assertForbidden();
        $this->delete(route('admin.models.destroy', $model->id))->assertForbidden();
        $this->post(route('admin.models.duplicate', $model->id))->assertForbidden();
        $this->get(route('admin.models.export', $model->id))->assertForbidden();
        $this->post(route('admin.models.import'))->assertForbidden();
        $this->get(route('admin.health'))->assertForbidden();
    }

    public function test_an_administrator_reaches_the_models_and_health_pages()
    {
        $this->as(Role::ADMINISTRATOR);

        $this->get(route('admin.models.index'))->assertOk();
        $this->get(route('admin.health'))->assertOk();
    }

    public function test_a_custom_role_gets_only_what_it_was_given()
    {
        $role = Role::create(['tenant_id' => 1, 'name' => 'Exporter']);
        $role->syncAbilities([Ability::MODELS_VIEW, Ability::MODELS_EXPORT]);
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        app(SiteAccess::class)->forget();
        $model = $this->model();

        $this->actingAs($user);

        $this->get(route('admin.models.index'))->assertOk();
        $this->delete(route('admin.models.destroy', $model->id))->assertForbidden();
        $this->get(route('admin.health'))->assertForbidden();
    }

    public function test_updates_and_modules_belong_to_the_install_owner_alone()
    {
        $this->as(Role::ADMINISTRATOR);

        $this->get(route('admin.updates'))->assertForbidden();
        $this->postJson(route('admin.updates.step'))->assertForbidden();
        $this->get(route('admin.modules.index'))->assertForbidden();

        $this->actingAs(User::factory()->installOwner()->create());
        app(SiteAccess::class)->forget();

        // Past the gate, the password prompt comes next.
        $this->get(route('admin.updates'))->assertRedirect(route('password.confirm'));
        $this->postJson(route('admin.updates.step'))->assertOk();
    }
}
