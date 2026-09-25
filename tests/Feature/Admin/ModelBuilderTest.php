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

/**
 * Every request here goes through the seeded site's own domain, not the
 * default site: UsesStarDust gives each test its own StarDust tenant (so
 * model slugs never collide between tests), and multisite is turned on so
 * `ResolveSite` picks that tenant up from the request's Host instead of
 * always resolving to site 1.
 */
class ModelBuilderTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    private const CORE_TYPES = [
        'text', 'email', 'url', 'color', 'hidden', 'radio', 'select', 'number',
        'range', 'checkbox', 'datetime', 'textarea', 'rich_text', 'code', 'checkboxes', 'file',
    ];

    private const TITLE = '10000000-0000-4000-8000-000000000001';

    private const SUMMARY = '10000000-0000-4000-8000-000000000002';

    private const EMBED = '10000000-0000-4000-8000-000000000003';

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $model = $this->seedModel();

        $this->get($this->url('admin.models.builder', ['model' => $model->id]))->assertRedirect(route('login'));
    }

    public function test_it_renders_a_model_with_its_fields_and_states()
    {
        $model = $this->seedModel();

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/models/Builder')
                ->where('model.id', $model->id)
                ->has('fieldTypes', count(self::CORE_TYPES))
                ->has('fieldTypes.0', fn (Assert $type) => $type
                    ->has('key')
                    ->has('label')
                    ->has('icon')
                    ->has('category')
                    ->has('settings')
                    ->has('storage', fn (Assert $storage) => $storage
                        ->has('declaredType')
                        ->has('canFilter')
                    )
                )
                ->where('schema.model.slug', $model->slug)
                ->has('schema.fields', 3)
                ->has('schema.fields.0', fn (Assert $field) => $field
                    ->has('uuid')
                    ->has('key')
                    ->has('label')
                    ->has('type')
                    ->has('required')
                    ->has('filterable')
                    ->has('layout_slot')
                    ->has('settings')
                    ->etc()
                )
                ->has('states', 3)
            );
    }

    public function test_another_sites_model_id_is_not_found()
    {
        $model = $this->seedModel();
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.builder', ['model' => $model->id], $other))
            ->assertNotFound();
    }

    public function test_opening_a_model_being_deleted_redirects_to_the_models_list()
    {
        $model = $this->seedModel();
        app(SchemaManager::class)->deleteModel($model);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertRedirect($this->url('admin.models.index'))
            ->assertInertiaFlash('toast.type', 'error');
    }

    public function test_guests_cannot_preview_or_save()
    {
        $model = $this->seedModel();

        $this->post($this->url('admin.models.builder.preview', ['model' => $model->id]), ['schema' => []])
            ->assertRedirect(route('login'));

        $this->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => []])
            ->assertRedirect(route('login'));
    }

    public function test_preview_and_save_require_a_schema()
    {
        $user = User::factory()->create();
        $model = $this->seedModel();

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.preview', ['model' => $model->id]), [])
            ->assertSessionHasErrors('schema');

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), [])
            ->assertSessionHasErrors('schema');
    }

    public function test_preview_diffs_the_posted_schema_against_the_stored_one()
    {
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);

        // Rename "title" -> "headline".
        $schema['fields'][0]['key'] = 'headline';

        // Retype "summary" from textarea to number (a real declared-type
        // change, unlike textarea -> text, which is still just a string),
        // and promote it to filterable.
        $schema['fields'][1]['type'] = 'number';
        $schema['fields'][1]['filterable'] = true;

        // Delete "embed".
        $schema['fields'] = array_values(array_filter(
            $schema['fields'],
            fn ($field) => $field['key'] !== 'embed',
        ));

        // Add a new field.
        $schema['fields'][] = [
            'uuid' => (string) Str::uuid(),
            'key' => 'excerpt',
            'label' => 'Excerpt',
            'type' => 'text',
            'required' => false,
            'filterable' => false,
            'layout_slot' => null,
            'settings' => [],
        ];

        // Change model metadata.
        $schema['model']['label'] = 'News Article';

        $props = $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.builder.preview', ['model' => $model->id]), ['schema' => $schema])
            ->assertOk()
            ->inertiaProps();

        $operations = collect($props['preview']['operations']);
        $this->assertEmpty($props['preview']['errors']);

        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'rename' && $op['field_uuid'] === self::TITLE));
        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'retype' && $op['field_uuid'] === self::SUMMARY));
        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'promote' && $op['field_uuid'] === self::SUMMARY));
        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'delete' && $op['field_uuid'] === self::EMBED));
        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'add'));
        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'metadata'));

        // A retype affects existing data (values that don't convert are
        // hidden from filters, not lost) without being destructive; delete
        // is both, since the field and its values are gone for good.
        $affecting = $operations->where('affects_existing_data', true)->pluck('op');
        $this->assertTrue($affecting->contains('retype'));
        $this->assertTrue($affecting->contains('delete'));

        $destructive = $operations->where('destructive', true)->pluck('op');
        $this->assertTrue($destructive->contains('delete'));
        $this->assertFalse($destructive->contains('retype'));
        $this->assertFalse($destructive->contains('add'));
        $this->assertFalse($destructive->contains('promote'));
    }

    public function test_preview_reports_key_and_label_problems_as_errors()
    {
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);

        // Cleared in the browser, so it arrives as null, not "": FieldSpec
        // must still parse it and let SchemaDiff report it per field.
        $schema['fields'][0]['label'] = '';
        $schema['fields'][1]['key'] = $schema['fields'][2]['key'];

        $props = $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.builder.preview', ['model' => $model->id]), ['schema' => $schema])
            ->assertOk()
            ->inertiaProps();

        $messages = collect($props['preview']['errors'])->pluck('message');

        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'needs a label')));
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'already uses the key')));
    }

    public function test_save_persists_the_schema()
    {
        $user = User::factory()->create();
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);
        $schema['model']['label'] = 'Changed Label';

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));

        $this->actingAs($user)
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertia(
                fn (Assert $page) => $page->where('schema.model.label', 'Changed Label')
            );
    }

    public function test_save_reports_failure_instead_of_claiming_success()
    {
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);
        $schema['fields'][0]['key'] = $schema['fields'][1]['key'];

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved', false)
                ->has('preview.errors')
            );

        // Nothing was written: the stored key is still the original one.
        $this->assertSame('title', app(SchemaManager::class)->export($model)['fields'][0]['key']);
    }

    /**
     * A model with three fields, one of every operation the diff tests
     * exercise: rename, retype, promote and delete.
     *
     * Drained once so every field starts "ready" rather than "indexing",
     * since StarDust refuses a rename or retype while a field's own
     * lifecycle is still in flight.
     */
    private function seedModel(): SchemaModel
    {
        $schema = new ModelSchema('article', 'Article', fields: [
            new FieldSpec(self::TITLE, 'title', 'Title', 'text', filterable: true),
            new FieldSpec(self::SUMMARY, 'summary', 'Summary', 'textarea'),
            new FieldSpec(self::EMBED, 'embed', 'Embed', 'code'),
        ]);

        $result = app(SchemaManager::class)->createModel($schema);

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertNotNull($result->model);

        $this->drain();

        return $result->model;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $route, array $parameters = [], ?Site $site = null): string
    {
        return 'http://'.($site ?? $this->site)->domains[0].route($route, $parameters, absolute: false);
    }
}
