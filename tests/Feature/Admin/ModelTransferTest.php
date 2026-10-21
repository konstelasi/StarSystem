<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteUser;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\CrossSite;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class ModelTransferTest extends TestCase
{
    use CrossSite, ManagesTestModels, RefreshDatabase, UsesStarDust;

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);
    }

    public function test_guests_cannot_export_or_import()
    {
        $model = $this->create('articles', 'Articles');

        $this->get($this->url('admin.models.export', ['model' => $model->id]))
            ->assertRedirect(route('login'));

        $this->post($this->url('admin.models.import'), [])
            ->assertRedirect(route('login'));
    }

    public function test_export_downloads_the_models_schema()
    {
        $model = $this->create('articles', 'Articles');

        $response = $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.export', ['model' => $model->id]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="articles.json"');

        $this->assertSame($this->manager()->export($model), $response->json());
    }

    public function test_exporting_a_model_being_deleted_redirects_with_a_toast()
    {
        $model = $this->create('articles', 'Articles');
        $this->manager()->deleteModel($model);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.export', ['model' => $model->id]))
            ->assertRedirect($this->url('admin.models.index'))
            ->assertInertiaFlash('toast.type', 'error');
    }

    public function test_another_sites_model_cannot_be_exported()
    {
        $model = $this->create('articles', 'Articles');
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.export', ['model' => $model->id], $other))
            ->assertNotFound();
    }

    public function test_import_creates_a_model_from_the_files_own_slug_and_label()
    {
        $file = $this->schemaFile('articles', 'Articles');

        $response = $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.import'), ['file' => $file]);

        $model = SchemaModel::where('slug', 'articles')->sole();
        $this->assertSame('Articles', $model->label);
        $this->assertSame(['title'], $model->fields()->pluck('key')->all());

        $response->assertRedirect($this->url('admin.models.builder', ['model' => $model->id]));
    }

    public function test_import_can_override_the_slug_and_label()
    {
        $file = $this->schemaFile('articles', 'Articles');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.import'), [
                'file' => $file,
                'slug' => 'articles_copy',
                'label' => 'Articles Copy',
            ]);

        $this->assertSame(0, SchemaModel::where('slug', 'articles')->count());

        $copy = SchemaModel::where('slug', 'articles_copy')->sole();
        $this->assertSame('Articles Copy', $copy->label);
    }

    public function test_malformed_json_is_rejected_as_a_file_error()
    {
        $file = UploadedFile::fake()->createWithContent('schema.json', 'not json');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.import'), ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, SchemaModel::query()->count());
    }

    public function test_a_slug_already_in_use_in_the_file_is_rejected_as_a_slug_error()
    {
        $this->create('articles', 'Articles');
        $file = $this->schemaFile('articles', 'Another Articles');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.import'), ['file' => $file])
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, SchemaModel::query()->count());
    }

    public function test_an_overriding_slug_already_in_use_is_rejected_as_a_slug_error()
    {
        $this->create('articles', 'Articles');
        $file = $this->schemaFile('other', 'Other');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.import'), ['file' => $file, 'slug' => 'articles'])
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, SchemaModel::query()->count());
    }

    public function test_guests_cannot_duplicate_a_model()
    {
        $model = $this->create('articles', 'Articles');

        $this->post($this->url('admin.models.duplicate', ['model' => $model->id]), [
            'slug' => 'articles_copy',
            'label' => 'Articles Copy',
        ])->assertRedirect(route('login'));
    }

    public function test_duplicating_a_model_copies_its_fields_with_new_ids()
    {
        $model = $this->create('articles', 'Articles');

        $response = $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.duplicate', ['model' => $model->id]), [
                'slug' => 'articles_copy',
                'label' => 'Articles Copy',
            ]);

        $copy = SchemaModel::where('slug', 'articles_copy')->sole();
        $this->assertSame('Articles Copy', $copy->label);
        $this->assertSame(['title', 'body'], $copy->fields()->pluck('key')->all());
        $this->assertNotSame($model->fields()->pluck('id')->all(), $copy->fields()->pluck('id')->all());

        // The source is unaffected.
        $this->assertSame(['title', 'body'], $model->fields()->pluck('key')->all());

        $response->assertRedirect($this->url('admin.models.builder', ['model' => $copy->id]));
    }

    public function test_duplicating_a_model_being_deleted_shows_an_error_toast()
    {
        $model = $this->create('articles', 'Articles');
        $this->manager()->deleteModel($model);

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.duplicate', ['model' => $model->id]), [
                'slug' => 'articles_copy',
                'label' => 'Articles Copy',
            ])
            ->assertRedirect($this->url('admin.models.index'))
            ->assertInertiaFlash('toast.type', 'error');
    }

    public function test_duplicating_with_a_taken_slug_is_rejected()
    {
        $model = $this->create('articles', 'Articles');
        $this->create('pages', 'Pages');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.duplicate', ['model' => $model->id]), [
                'slug' => 'pages',
                'label' => 'Another Pages',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_another_sites_model_cannot_be_duplicated()
    {
        $model = $this->create('articles', 'Articles');
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.duplicate', ['model' => $model->id], $other), [
                'slug' => 'articles_copy',
                'label' => 'Articles Copy',
            ])
            ->assertNotFound();
    }

    public function test_an_import_cannot_link_to_a_model_the_importer_cannot_view()
    {
        $authors = $this->create('authors', 'Authors');
        $message = '"Author": your role can\'t view Authors entries, so it can\'t link to them.';

        // May import models, but holds nothing on Authors.
        $this->actingAs($this->member([Ability::MODELS_IMPORT]))
            ->post($this->url('admin.models.import'), ['file' => $this->linkingFile($authors)])
            ->assertSessionHasErrors(['file' => $message]);
        $this->assertFalse(SchemaModel::where('slug', 'posts')->exists());

        // Someone who can see Authors' entries as well may link to them.
        $this->actingAs($this->member([Ability::MODELS_IMPORT], ['authors' => [Ability::ENTRIES_VIEW]]))
            ->post($this->url('admin.models.import'), ['file' => $this->linkingFile($authors)])
            ->assertSessionHasNoErrors();
        $this->assertTrue(SchemaModel::where('slug', 'posts')->exists());
    }

    public function test_a_copy_cannot_link_to_a_model_the_copier_cannot_view()
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->manager()->createModel(new ModelSchema('posts', 'Posts', fields: [
            new FieldSpec((string) Str::uuid(), 'author', 'Author', 'relation', $this->linkTo($authors)),
        ]))->model;
        $this->assertNotNull($posts);
        $message = '"Author": your role can\'t view Authors entries, so it can\'t link to them.';

        $this->actingAs($this->member([Ability::MODELS_CREATE]))
            ->post($this->url('admin.models.duplicate', ['model' => $posts->id]), ['slug' => 'posts_copy', 'label' => 'Posts Copy'])
            ->assertSessionHasErrors(['slug' => $message]);
        $this->assertFalse(SchemaModel::where('slug', 'posts_copy')->exists());

        $this->actingAs($this->member([Ability::MODELS_CREATE], ['authors' => [Ability::ENTRIES_VIEW]]))
            ->post($this->url('admin.models.duplicate', ['model' => $posts->id]), ['slug' => 'posts_copy', 'label' => 'Posts Copy'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(SchemaModel::where('slug', 'posts_copy')->exists());
    }

    /**
     * A file for a Posts model with a relation to $target.
     */
    private function linkingFile(SchemaModel $target): UploadedFile
    {
        $schema = (new ModelSchema('posts', 'Posts', fields: [
            new FieldSpec((string) Str::uuid(), 'author', 'Author', 'relation', $this->linkTo($target)),
        ]))->toJson();

        return UploadedFile::fake()->createWithContent('schema.json', (string) json_encode($schema));
    }

    /**
     * The settings of a relation to $target, naming its entries by its title.
     *
     * @return array<string, mixed>
     */
    private function linkTo(SchemaModel $target): array
    {
        return ['link' => ['model' => $target->id, 'label_field' => (string) $target->fields()->where('key', 'title')->value('id')], 'multiple' => false];
    }

    /**
     * A member whose role holds $global for every model and $scoped for the
     * models named by slug.
     *
     * @param  list<string>  $global
     * @param  array<string, list<string>>  $scoped
     */
    private function member(array $global, array $scoped = []): User
    {
        static $n = 0;
        $role = Role::create(['tenant_id' => $this->site->id, 'name' => 'Custom '.++$n]);
        $role->syncAbilities($global);
        $role->syncModelAbilities(collect($scoped)->mapWithKeys(fn (array $abilities, string $slug) => [
            SchemaModel::query()->where('slug', $slug)->value('id') => $abilities,
        ])->all());

        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function schemaFile(string $slug, string $label): UploadedFile
    {
        $schema = (new ModelSchema($slug, $label, fields: [
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
        ]))->toJson();

        return UploadedFile::fake()->createWithContent('schema.json', (string) json_encode($schema));
    }
}
