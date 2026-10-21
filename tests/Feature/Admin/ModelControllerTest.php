<?php

namespace Tests\Feature\Admin;

use App\Models\SchemaModel;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class ModelControllerTest extends TestCase
{
    use CrossSite, ManagesTestModels, RefreshDatabase, UsesStarDust;

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

    public function test_the_sidebar_gets_the_sites_active_models_by_group_then_label()
    {
        $this->create('pages', 'Pages');
        $this->create('articles', 'Articles')->update(['group' => 'Blog']);
        $this->create('authors', 'Authors')->update(['group' => 'Blog']);
        $this->manager()->deleteModel($this->create('drafts', 'Drafts'));

        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');
        $this->asSite($other);
        $this->create('secrets', 'Secrets');
        $this->asSite($this->site);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.index'))
            ->assertInertia(fn (Assert $assert) => $assert
                ->has('navModels', 3)
                ->where('navModels.0.label', 'Pages')
                ->where('navModels.0.group', null)
                ->where('navModels.1.label', 'Articles')
                ->where('navModels.1.group', 'Blog')
                ->where('navModels.2.label', 'Authors')
            );
    }

    public function test_guests_get_no_models_in_the_sidebar()
    {
        $this->create('articles', 'Articles');

        $this->get($this->url('home'))
            ->assertInertia(fn (Assert $assert) => $assert->where('navModels', []));
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

    public function test_a_model_another_model_links_to_is_not_deleted()
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->create('posts', 'Posts');
        $this->link($posts, $authors);

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.destroy', ['model' => $authors->id]))
            ->assertRedirect($this->url('admin.models.index'))
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Other models link to this one: Posts through Author. Remove those fields first.');

        $this->assertSame(SchemaModel::ACTIVE, $authors->refresh()->status);
        $this->assertSame(2, $authors->fields()->count());
    }

    public function test_the_message_names_at_most_three_links()
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->create('posts', 'Posts');
        foreach (['author', 'editor', 'reviewer', 'proofreader'] as $key) {
            $this->link($posts, $authors, $key);
        }

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.destroy', ['model' => $authors->id]))
            ->assertInertiaFlash('toast.message', 'Other models link to this one: Posts through Author, Posts through Editor, Posts through Reviewer. Remove those fields first.');

        $this->assertSame(SchemaModel::ACTIVE, $authors->refresh()->status);
    }

    public function test_a_self_link_does_not_stop_a_delete()
    {
        $authors = $this->create('authors', 'Authors');
        $this->link($authors, $authors, 'mentor');

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.destroy', ['model' => $authors->id]))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSame(SchemaModel::DELETING, $authors->refresh()->status);

        $this->drain();
    }

    public function test_a_model_can_be_deleted_once_the_link_is_removed()
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->create('posts', 'Posts');
        $this->link($posts, $authors);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete($this->url('admin.models.destroy', ['model' => $authors->id]))
            ->assertInertiaFlash('toast.type', 'error');

        $schema = ModelSchema::fromModel($posts);
        $result = $this->manager()->save($posts, $schema->withFields(array_values(array_filter($schema->fields, fn (FieldSpec $field) => $field->type !== 'relation'))));
        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        $this->actingAs($user)
            ->delete($this->url('admin.models.destroy', ['model' => $authors->id]))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSame(SchemaModel::DELETING, $authors->refresh()->status);

        $this->drain();
    }

    /**
     * Adds a relation field to $from that links to $to, naming its entries
     * by their title.
     */
    private function link(SchemaModel $from, SchemaModel $to, string $key = 'author'): void
    {
        $schema = ModelSchema::fromModel($from);
        $title = (string) $to->fields()->where('key', 'title')->value('id');

        $result = $this->manager()->save($from, $schema->withFields([
            ...$schema->fields,
            new FieldSpec((string) Str::uuid(), $key, Str::headline($key), 'relation', ['link' => ['model' => $to->id, 'label_field' => $title]]),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
    }
}
