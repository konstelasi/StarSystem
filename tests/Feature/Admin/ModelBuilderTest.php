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
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use App\StarDust\StarDustService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\Fakes\FlakyStarDust;
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
    use CrossSite, ManagesTestModels, RefreshDatabase, UsesStarDust;

    private const CORE_TYPES = [
        'text', 'email', 'url', 'color', 'hidden', 'radio', 'select', 'number',
        'range', 'checkbox', 'datetime', 'textarea', 'rich_text', 'code', 'checkboxes', 'file', 'relation',
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
                ->where('model.schema_rev', $model->schema_rev)
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

    public function test_other_slugs_leaves_out_the_models_own_slug()
    {
        $model = $this->seedModel();
        $sibling = app(SchemaManager::class)->createModel(new ModelSchema('pages', 'Pages'))->model;

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('otherSlugs', [$sibling->slug])
            );
    }

    public function test_saving_a_new_slug_renames_the_model_in_stardust_and_keeps_the_same_url()
    {
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);
        $schema['model']['slug'] = 'stories';

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));

        $this->assertSame('stories', $model->refresh()->slug);
        $this->assertSame('stories', app(SchemaManager::class)->export($model)['model']['slug']);

        // The same id keeps resolving, since the URL never used the slug.
        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schema.model.slug', 'stories'));
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
            ->post($this->url('admin.models.builder.preview', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
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
            ->post($this->url('admin.models.builder.preview', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
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
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
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
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved', false)
                ->has('preview.errors')
            );

        // Nothing was written: the stored key is still the original one.
        $this->assertSame('title', app(SchemaManager::class)->export($model)['fields'][0]['key']);
    }

    public function test_a_save_that_stops_partway_can_be_finished_from_the_builder()
    {
        $user = User::factory()->create();
        $model = $this->seedModel();
        $flaky = $this->swapStarDust(failOn: 2);

        $schema = app(SchemaManager::class)->export($model);
        $schema['fields'][] = $this->newField('excerpt');
        $schema['fields'][] = $this->newField('byline');

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved', false)
                ->where('unfinished.error', fn (string $error) => str_contains($error, 'StarDust went away'))
            );

        $this->actingAs($user)
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page->has('unfinished.error'));

        // Still failing: the error comes back and the banner stays.
        $flaky->calls = 0;
        $flaky->failOn = 1;
        $this->actingAs($user)
            ->post($this->url('admin.models.builder.retry', ['model' => $model->id]))
            ->assertRedirect($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertiaFlash('toast.type', 'error');
        $this->assertNotNull(app(SchemaManager::class)->unfinished($model));

        $flaky->failOn = null;
        $this->actingAs($user)
            ->post($this->url('admin.models.builder.retry', ['model' => $model->id]))
            ->assertRedirect($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertiaFlash('toast.message', 'The last save finished.');

        $this->actingAs($user)
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('unfinished', null)
                ->has('schema.fields', 5)
            );
    }

    public function test_a_save_returns_the_field_badges_it_set_working()
    {
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);
        $schema['fields'][0]['key'] = 'headline';

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved', true)
                ->where('states.'.self::TITLE, 'renaming')
                ->where('states.'.self::SUMMARY, 'ready')
            );
    }

    public function test_the_builder_can_reload_just_the_field_badges()
    {
        $model = $this->seedModel();

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('states', fn (Assert $reload) => $reload
                    ->has('states', 3)
                    ->missing('schema')
                    ->missing('fieldTypes')
                )
            );
    }

    public function test_finishing_with_nothing_unfinished_just_returns_to_the_builder()
    {
        $model = $this->seedModel();

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.builder.retry', ['model' => $model->id]))
            ->assertRedirect($this->url('admin.models.builder', ['model' => $model->id]))
            ->assertInertiaFlashMissing('toast');
    }

    public function test_preview_and_save_require_the_revision_the_tab_opened()
    {
        $user = User::factory()->create();
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.preview', ['model' => $model->id]), ['schema' => $schema])
            ->assertSessionHasErrors('rev');

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema])
            ->assertSessionHasErrors('rev');
    }

    /**
     * A tab that opened the model before another tab saved it would
     * otherwise diff its older schema against the new one and undo that
     * save, deleting any field the other tab added along with its values.
     */
    public function test_a_save_from_an_outdated_tab_is_refused_and_changes_nothing()
    {
        $user = User::factory()->create();
        $model = $this->seedModel();
        $opened = $model->schema_rev;

        $other = app(SchemaManager::class)->export($model);
        $other['model']['label'] = 'Saved Elsewhere';
        $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $other, 'rev' => $opened])
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));

        $stale = $other;
        $stale['model']['label'] = 'Article';
        $stale['fields'][0]['key'] = 'headline';

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.preview', ['model' => $model->id]), ['schema' => $stale, 'rev' => $opened])
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.errors.0.message', fn (string $message) => str_contains($message, 'after you opened it'))
            );

        $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $stale, 'rev' => $opened])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved', false)
                ->where('preview.errors.0.message', fn (string $message) => str_contains($message, 'after you opened it'))
            );

        $stored = app(SchemaManager::class)->export($model->refresh());
        $this->assertSame('Saved Elsewhere', $stored['model']['label']);
        $this->assertSame('title', $stored['fields'][0]['key']);
    }

    public function test_a_tab_can_save_again_with_the_revision_its_last_save_returned()
    {
        $user = User::factory()->create();
        $model = $this->seedModel();
        $schema = app(SchemaManager::class)->export($model);

        $schema['model']['label'] = 'First Save';
        $rev = $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema, 'rev' => $model->schema_rev])
            ->assertInertia(fn (Assert $page) => $page->where('saved', true))
            ->inertiaProps('rev');

        $this->assertSame($model->refresh()->schema_rev, $rev);

        $schema['model']['label'] = 'Second Save';
        $this->actingAs($user)
            ->post($this->url('admin.models.builder.save', ['model' => $model->id]), ['schema' => $schema, 'rev' => $rev])
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));

        $this->assertSame('Second Save', app(SchemaManager::class)->export($model->refresh())['model']['label']);
    }

    public function test_a_link_to_a_model_the_saver_cannot_view_is_refused()
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->create('posts', 'Posts');
        $schema = app(SchemaManager::class)->export($posts);
        $schema['fields'][] = $this->relationField($authors);
        $message = '"Author": your role can\'t view Authors entries, so it can\'t link to them.';

        // May change Posts, but holds nothing on Authors.
        $builder = $this->member([], ['posts' => [Ability::MODELS_UPDATE, Ability::ENTRIES_VIEW]]);

        $this->actingAs($builder)
            ->post($this->url('admin.models.builder.preview', ['model' => $posts->id]), ['schema' => $schema, 'rev' => $posts->schema_rev])
            ->assertInertia(fn (Assert $page) => $page->where('preview.errors.0.message', $message));

        $this->actingAs($builder)
            ->post($this->url('admin.models.builder.save', ['model' => $posts->id]), ['schema' => $schema, 'rev' => $posts->schema_rev])
            ->assertInertia(fn (Assert $page) => $page
                ->where('saved', false)
                ->where('preview.errors.0.message', $message)
            );
        $this->assertSame(2, $posts->fields()->count());

        // Someone who can see Authors' entries as well may link to them.
        $reader = $this->member([], ['posts' => [Ability::MODELS_UPDATE], 'authors' => [Ability::ENTRIES_VIEW]]);

        $this->actingAs($reader)
            ->post($this->url('admin.models.builder.save', ['model' => $posts->id]), ['schema' => $schema, 'rev' => $posts->schema_rev])
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));
        $this->assertSame(3, $posts->fields()->count());
    }

    public function test_a_link_the_saver_cannot_view_does_not_block_other_changes()
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->create('posts', 'Posts');
        $schema = app(SchemaManager::class)->export($posts);
        $schema['fields'][] = $this->relationField($authors);

        $reader = $this->member([], ['posts' => [Ability::MODELS_UPDATE], 'authors' => [Ability::ENTRIES_VIEW]]);
        $this->actingAs($reader)
            ->post($this->url('admin.models.builder.save', ['model' => $posts->id]), ['schema' => $schema, 'rev' => $posts->schema_rev])
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));

        $schema = app(SchemaManager::class)->export($posts->refresh());
        $schema['model']['label'] = 'Stories';

        $builder = $this->member([], ['posts' => [Ability::MODELS_UPDATE, Ability::ENTRIES_VIEW]]);
        $this->actingAs($builder)
            ->post($this->url('admin.models.builder.save', ['model' => $posts->id]), ['schema' => $schema, 'rev' => $posts->schema_rev])
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));
        $this->assertSame('Stories', $posts->refresh()->label);
    }

    public function test_the_builder_lists_models_the_person_can_view()
    {
        $posts = $this->create('posts', 'Posts');
        $authors = $this->create('authors', 'Authors');
        $this->create('secrets', 'Secrets');
        $this->create('old', 'Old')->update(['status' => SchemaModel::DELETING]);

        // May change Posts, and view the entries of Authors and of Old, which is
        // being deleted. Secrets they can't view.
        $builder = $this->member([], ['posts' => [Ability::MODELS_UPDATE], 'authors' => [Ability::ENTRIES_VIEW], 'old' => [Ability::ENTRIES_VIEW]]);

        $this->actingAs($builder)
            ->get($this->url('admin.models.builder', ['model' => $posts->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('models', 2)
                ->where('models.0.id', $authors->id)
                ->where('models.0.label', 'Authors')
                ->has('models.0.fields', 2)
                ->has('models.0.fields.0', fn (Assert $field) => $field
                    ->where('key', 'title')
                    ->where('type', 'text')
                    ->hasAll(['uuid', 'label', 'settings'])
                )
                // This model is listed whether or not they can view its
                // entries, with its own fields.
                ->where('models.1.id', $posts->id)
                ->has('models.1.fields', 2)
            );
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

    /**
     * A relation field as the builder posts it, naming $target's entries by
     * its title.
     *
     * @return array<string, mixed>
     */
    private function relationField(SchemaModel $target): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'key' => 'author',
            'label' => 'Author',
            'type' => 'relation',
            'required' => false,
            'filterable' => false,
            'layout_slot' => null,
            'settings' => ['link' => ['model' => $target->id, 'label_field' => $target->fields()->where('key', 'title')->value('id')], 'multiple' => false],
        ];
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
     * Makes StarDust's Nth schema write fail, like a connection that drops
     * half-way through a save.
     */
    private function swapStarDust(?int $failOn = null): FlakyStarDust
    {
        $fake = new FlakyStarDust(app(), app(CurrentSite::class));
        $fake->failOn = $failOn;
        $this->app->instance(StarDustService::class, $fake);

        return $fake;
    }

    /**
     * A plain text field as the builder posts it.
     *
     * @return array<string, mixed>
     */
    private function newField(string $key): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'key' => $key,
            'label' => Str::headline($key),
            'type' => 'text',
            'required' => false,
            'filterable' => false,
            'layout_slot' => null,
            'settings' => [],
        ];
    }
}
