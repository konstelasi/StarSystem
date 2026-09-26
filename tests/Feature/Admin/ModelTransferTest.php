<?php

namespace Tests\Feature\Admin;

use App\Models\SchemaModel;
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

    private function schemaFile(string $slug, string $label): UploadedFile
    {
        $schema = (new ModelSchema($slug, $label, fields: [
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
        ]))->toJson();

        return UploadedFile::fake()->createWithContent('schema.json', (string) json_encode($schema));
    }
}
