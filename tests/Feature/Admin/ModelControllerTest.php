<?php

namespace Tests\Feature\Admin;

use App\Models\SchemaModel;
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
    private function url(string $route, array $parameters = []): string
    {
        return 'http://'.$this->site->domains[0].route($route, $parameters, absolute: false);
    }
}
