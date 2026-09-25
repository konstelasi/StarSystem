<?php

namespace Tests\Feature\Admin;

use App\Models\SchemaModel;
use App\Models\Site;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class ModelControllerTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get($this->url('admin.models.index'))->assertRedirect(route('login'));
    }

    public function test_it_lists_the_sites_models_with_their_field_counts_and_status()
    {
        $article = $this->create('articles', 'Articles');
        $page = $this->create('pages', 'Pages');
        $this->manager()->deleteModel($page);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('admin/models/Index')
                // Ordered by label: "Articles" before "Pages".
                ->has('models', 2)
                ->has('models.0', fn (Assert $row) => $row
                    ->where('id', $article->id)
                    ->where('slug', 'articles')
                    ->where('label', 'Articles')
                    ->where('fields_count', 2)
                    ->where('status', 'active')
                    ->has('group')
                    ->has('updated_at')
                )
                ->has('models.1', fn (Assert $row) => $row
                    ->where('id', $page->id)
                    ->where('status', 'deleting')
                    ->where('fields_count', 0)
                    ->etc()
                )
            );
    }

    public function test_a_purged_model_drops_off_the_list()
    {
        $model = $this->create('articles', 'Articles');
        $this->manager()->deleteModel($model);
        $this->drain();

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.index'))
            ->assertInertia(fn (Assert $assert) => $assert->has('models', 0));

        $this->assertNull(SchemaModel::find($model->id));
    }

    public function test_models_are_invisible_across_sites()
    {
        $owner = $this->site;
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->create('articles', 'Articles');

        $this->assertInvisibleAcrossSites(
            $owner,
            $other,
            fn () => null,
            fn () => SchemaModel::query()->count(),
        );
    }

    public function test_the_old_builder_url_redirects_to_the_models_list()
    {
        $this->actingAs(User::factory()->create())
            ->get('http://'.$this->site->domains[0].'/admin/models/builder')
            ->assertRedirect($this->url('admin.models.index'));
    }

    public function test_guests_cannot_create_a_model()
    {
        $this->post($this->url('admin.models.store'), ['slug' => 'articles', 'label' => 'Articles'])
            ->assertRedirect(route('login'));
    }

    public function test_creating_a_model_redirects_to_its_builder()
    {
        $response = $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.store'), [
                'slug' => 'articles',
                'label' => 'Articles',
                'group' => 'Content',
            ]);

        $model = SchemaModel::sole();
        $this->assertSame('articles', $model->slug);
        $this->assertSame('Articles', $model->label);
        $this->assertSame('Content', $model->group);

        $response->assertRedirect($this->url('admin.models.builder', ['model' => $model->id]));
    }

    public function test_slug_and_label_are_required()
    {
        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.store'), [])
            ->assertSessionHasErrors(['slug', 'label']);

        $this->assertSame(0, SchemaModel::query()->count());
    }

    public function test_an_invalid_slug_is_rejected()
    {
        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.store'), ['slug' => 'Not Valid', 'label' => 'X'])
            ->assertSessionHasErrors('slug');

        $this->assertSame(0, SchemaModel::query()->count());
    }

    public function test_a_taken_slug_is_rejected()
    {
        $this->create('articles', 'Articles');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.store'), ['slug' => 'articles', 'label' => 'Again'])
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, SchemaModel::query()->count());
    }

    public function test_a_slug_held_by_a_deleted_model_is_rejected()
    {
        $model = $this->create('articles', 'Articles');
        $this->manager()->deleteModel($model);

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.store'), ['slug' => 'articles', 'label' => 'Again'])
            ->assertSessionHasErrors('slug');
    }

    public function test_guests_cannot_delete_a_model()
    {
        $model = $this->create('articles', 'Articles');

        $this->delete($this->url('admin.models.destroy', ['model' => $model->id]))
            ->assertRedirect(route('login'));
    }

    public function test_deleting_a_model_marks_it_deleting_until_stardust_purges_it()
    {
        $model = $this->create('articles', 'Articles');

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.destroy', ['model' => $model->id]))
            ->assertRedirect($this->url('admin.models.index'))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSame(SchemaModel::DELETING, $model->refresh()->status);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.index'))
            ->assertInertia(fn (Assert $assert) => $assert
                ->has('models.0', fn (Assert $row) => $row->where('status', 'deleting')->etc())
            );

        $this->drain();

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.index'))
            ->assertInertia(fn (Assert $assert) => $assert->has('models', 0));
    }

    public function test_deleting_a_model_twice_shows_an_error_toast()
    {
        $model = $this->create('articles', 'Articles');
        $user = User::factory()->create();

        $this->actingAs($user)->delete($this->url('admin.models.destroy', ['model' => $model->id]));

        $this->actingAs($user)
            ->delete($this->url('admin.models.destroy', ['model' => $model->id]))
            ->assertRedirect($this->url('admin.models.index'))
            ->assertInertiaFlash('toast.type', 'error');
    }

    public function test_another_sites_model_cannot_be_deleted()
    {
        $model = $this->create('articles', 'Articles');
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.destroy', ['model' => $model->id], $other))
            ->assertNotFound();

        $this->assertSame(SchemaModel::ACTIVE, $model->refresh()->status);
    }

    private function create(string $slug, string $label): SchemaModel
    {
        $result = $this->manager()->createModel(new ModelSchema($slug, $label, fields: [
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
            new FieldSpec((string) Str::uuid(), 'body', 'Body', 'textarea'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        return $result->model;
    }

    private function manager(): SchemaManager
    {
        return app(SchemaManager::class);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $route, array $parameters = [], ?Site $site = null): string
    {
        return 'http://'.($site ?? $this->site)->domains[0].route($route, $parameters, absolute: false);
    }
}
