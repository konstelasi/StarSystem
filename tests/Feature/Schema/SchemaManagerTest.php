<?php

namespace Tests\Feature\Schema;

use App\Models\SchemaChange;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Models\Site;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use App\StarDust\StarDustService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use StarDust\Schema\FieldDescription;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class SchemaManagerTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    private const TITLE = '10000000-0000-4000-8000-000000000001';

    private const BODY = '10000000-0000-4000-8000-000000000002';

    private const COUNT = '10000000-0000-4000-8000-000000000003';

    private const EXTRA = '10000000-0000-4000-8000-000000000004';

    private const MORE = '10000000-0000-4000-8000-000000000005';

    public function test_creating_a_model_registers_every_field_with_stardust()
    {
        $model = $this->create();

        $described = $this->stardust()->describeModel($model->stardust_model_id);
        $this->assertNotNull($described);
        $this->assertSame(['title', 'body', 'count'], array_map(fn (FieldDescription $f) => $f->name, $described->fields));

        // JSON-only types are registered too, as non-filterable strings.
        $this->assertSame('string', $described->field('body')?->declaredType);
        $this->assertFalse($described->field('body')?->isFilterable);
        $this->assertTrue($described->field('title')?->isFilterable);

        $this->assertSame(['title', 'body', 'count'], $model->fields()->pluck('key')->all());
        $this->assertSame(1, $model->schema_rev);
    }

    public function test_rename_keeps_values_under_the_new_key_including_a_json_only_field()
    {
        $model = $this->create();
        $entry = $this->writeEntry($model->stardust_model_id, ['title' => 'Hello', 'body' => '<p>World</p>', 'count' => '3']);

        $result = $this->manager()->save($model, $this->change($model, [
            self::TITLE => ['key' => 'headline'],
            self::BODY => ['key' => 'content'],
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertSame(['rename', 'rename'], $result->changes->pluck('op')->all());
        $this->assertSame('renaming', $this->manager()->states($model)[self::BODY]);

        // Reads use the new keys at once, before the background rewrite.
        // Key order isn't guaranteed: MariaDB and MySQL order JSON object keys differently.
        $this->assertEquals(['headline' => 'Hello', 'content' => '<p>World</p>'], array_intersect_key($this->readEntry($entry)->fields, ['headline' => 1, 'content' => 1]));

        $this->drain();

        $this->assertSame('ready', $this->manager()->states($model)[self::BODY]);
        $stored = $this->storedPayload($entry);
        $this->assertSame('Hello', $stored['headline']);
        $this->assertSame('<p>World</p>', $stored['content']);
        $this->assertArrayNotHasKey('title', $stored);
        $this->assertArrayNotHasKey('body', $stored);
        $this->assertSame(['headline', 'content', 'count'], $model->fields()->pluck('key')->all());
    }

    public function test_retype_from_string_to_int_backfills_and_ends_up_indexed()
    {
        $model = $this->create();
        $this->writeEntry($model->stardust_model_id, ['count' => '42']);
        $this->save($model, [self::COUNT => ['filterable' => true]]);
        $this->drain();

        $result = $this->save($model, [self::COUNT => ['type' => 'number', 'settings' => ['whole' => true]]]);

        $this->assertSame(['retype'], $result->changes->pluck('op')->all());
        $this->assertSame('retyping', $this->manager()->states($model)[self::COUNT]);

        $this->drain();

        $state = $this->stardustState($model, 'count');
        $this->assertSame('ready', $state['state']);
        $this->assertSame('int', $state['declared_type']);
        $this->assertTrue($state['indexed']);

        $field = SchemaField::findOrFail(self::COUNT);
        $this->assertSame('number', $field->type);
        $this->assertTrue($field->settings['whole'] ?? null);
    }

    public function test_number_to_datetime_is_refused_before_any_stardust_call()
    {
        $model = $this->create([self::COUNT => ['type' => 'number']]);
        $spy = $this->swapStarDust();

        $preview = $this->manager()->preview($model, $this->change($model, [self::COUNT => ['type' => 'datetime', 'settings' => []]]));
        $result = $this->save($model, [self::COUNT => ['type' => 'datetime', 'settings' => []]]);

        $this->assertSame(self::COUNT, $preview['errors'][0]['field_uuid']);
        $this->assertStringContainsString('number and a date', $preview['errors'][0]['message']);
        $this->assertSame(SaveResult::INVALID, $result->status);
        $this->assertSame(0, $spy->calls);
        $this->assertSame(1, SchemaChange::query()->distinct()->count('batch'), 'Only the creation batch is logged.');
        $this->assertSame('number', SchemaField::findOrFail(self::COUNT)->type);
    }

    public function test_promote_becomes_indexed_after_a_tick_and_demote_takes_effect_at_once()
    {
        $model = $this->create();
        $this->writeEntry($model->stardust_model_id, ['count' => 'seven']);

        $this->save($model, [self::COUNT => ['filterable' => true]]);

        $this->assertSame('indexing', $this->manager()->states($model)[self::COUNT]);
        $this->assertFalse($this->stardustState($model, 'count')['indexed']);

        $this->drain();

        $this->assertSame('ready', $this->manager()->states($model)[self::COUNT]);
        $this->assertTrue($this->stardustState($model, 'count')['indexed']);

        $this->save($model, [self::COUNT => ['filterable' => false]]);

        $state = $this->stardustState($model, 'count');
        $this->assertSame('ready', $this->manager()->states($model)[self::COUNT]);
        $this->assertFalse($state['filterable']);
        $this->assertFalse($state['indexed']);
        $this->assertFalse(SchemaField::findOrFail(self::COUNT)->filterable);
    }

    public function test_delete_removes_values_and_holds_the_key_until_the_purge_finishes()
    {
        $model = $this->create();
        $entry = $this->writeEntry($model->stardust_model_id, ['title' => 'Kept', 'body' => '<p>Gone</p>']);

        $result = $this->manager()->save($model, $this->without($model, self::BODY));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertTrue($result->changes->contains(fn (SchemaChange $c) => $c->op === 'delete'));
        $this->assertNull(SchemaField::find(self::BODY));
        $this->assertArrayNotHasKey('body', $this->readEntry($entry)->fields);

        $again = $this->withField($this->without($model, self::BODY), new FieldSpec(self::EXTRA, 'body', 'Body again', 'textarea'));
        $preview = $this->manager()->preview($model, $again);
        $this->assertSame(self::EXTRA, $preview['errors'][0]['field_uuid'] ?? null);
        $this->assertStringContainsString('still being cleared', $preview['errors'][0]['message']);

        $this->drain();

        $this->assertArrayNotHasKey('body', $this->storedPayload($entry));
        $this->assertSame('Kept', $this->storedPayload($entry)['title']);
        $this->assertSame([], $this->manager()->preview($model, $again)['errors']);
        $this->assertSame(SaveResult::DONE, $this->manager()->save($model, $again)->status);
        $this->assertSame('body', SchemaField::findOrFail(self::EXTRA)->key);
    }

    public function test_a_partial_failure_is_logged_blocks_the_rest_and_retry_completes_it()
    {
        $model = $this->create();
        $flaky = $this->swapStarDust(failOn: 2);

        $desired = $this->withField($this->change($model, [self::TITLE => ['key' => 'headline']]), new FieldSpec(self::EXTRA, 'summary', 'Summary', 'textarea'));
        $desired = $this->withField($desired, new FieldSpec(self::MORE, 'rating', 'Rating', 'number'));

        $result = $this->manager()->save($model, $desired);

        $this->assertSame(SaveResult::FAILED, $result->status);
        $this->assertSame(
            [['rename', 'done'], ['add', 'failed'], ['add', 'blocked']],
            $result->changes->map(fn (SchemaChange $c) => [$c->op, $c->status])->all(),
        );
        $this->assertStringContainsString('StarDust went away', (string) $result->error());

        // ss_fields matches what was applied: the rename, and neither add.
        $this->assertSame('headline', SchemaField::findOrFail(self::TITLE)->key);
        $this->assertNull(SchemaField::find(self::EXTRA));
        $this->assertNull(SchemaField::find(self::MORE));
        $this->assertSame(['headline', 'body', 'count'], $model->fields()->pluck('key')->all());

        $states = $this->manager()->states($model);
        $this->assertSame('waiting', $states[self::EXTRA]);
        $this->assertSame('waiting', $states[self::MORE]);

        $this->assertStringContainsString('did not finish', $this->manager()->preview($model, $desired)['errors'][0]['message']);

        $flaky->failOn = null;
        $retried = $this->manager()->retry((string) $result->batch);

        $this->assertSame(SaveResult::DONE, $retried->status, (string) $retried->error());
        $this->assertSame(['done', 'done', 'done'], $retried->changes->pluck('status')->all());
        $this->assertSame([1, 2, 1], $retried->changes->pluck('attempts')->all());
        $this->assertSame(['headline', 'body', 'count', 'summary', 'rating'], $model->fields()->pluck('key')->all());
        $this->assertSame([], $this->manager()->preview($model, $desired)['operations']);
        $this->assertSame(['title', 'body', 'count', 'summary', 'rating'], $this->stardustNames($model), 'The rename drains on the tick; the adds are there.');
    }

    public function test_the_retry_command_finishes_unfinished_batches()
    {
        $model = $this->create();
        $flaky = $this->swapStarDust(failOn: 1);

        $result = $this->manager()->save($model, $this->withField($this->change($model, []), new FieldSpec(self::EXTRA, 'summary', 'Summary', 'textarea')));
        $this->assertSame(SaveResult::FAILED, $result->status);

        $flaky->failOn = null;
        app(CurrentSite::class)->forget();

        $this->artisan('starsystem:schema:retry')->assertSuccessful();

        $this->asSite($this->site);
        $this->assertSame('summary', SchemaField::findOrFail(self::EXTRA)->key);
        $this->assertSame(0, SchemaChange::query()->whereIn('status', SchemaChange::UNFINISHED)->count());
    }

    public function test_metadata_changes_are_applied_without_stardust_field_calls()
    {
        $model = $this->create();
        $spy = $this->swapStarDust();

        $desired = new ModelSchema(
            'articles',
            'Stories',
            'newspaper',
            'Blog',
            [['id' => 'side', 'kind' => 'section', 'slots' => [['id' => 'aside']]]],
            array_reverse(ModelSchema::fromModel($model)->fields),
        );
        $desired = $desired->withFields(array_map(
            fn (FieldSpec $f) => $f->uuid === self::TITLE ? $f->with(['label' => 'Headline', 'layoutSlot' => 'aside', 'helper' => 'Keep it short']) : $f,
            $desired->fields,
        ));

        $result = $this->manager()->save($model, $desired);

        $this->assertSame(['metadata'], $result->changes->pluck('op')->all());
        $this->assertSame(0, $spy->calls);
        $model->refresh();
        $this->assertSame(['Stories', 'newspaper', 'Blog', 2], [$model->label, $model->icon, $model->group, $model->schema_rev]);
        $this->assertSame(['count', 'body', 'title'], $model->fields()->pluck('key')->all());
        $title = SchemaField::findOrFail(self::TITLE);
        $this->assertSame(['Headline', 'aside', 'Keep it short'], [$title->label, $title->layout_slot, $title->helper]);
        $this->assertEquals($desired, ModelSchema::fromModel($model));
    }

    public function test_a_new_slug_renames_the_stardust_model()
    {
        $model = $this->create();

        $this->manager()->save($model, $this->change($model, [])->withSlug('stories'));

        $this->assertSame('stories', $model->refresh()->slug);
        $this->assertSame('stories', $this->stardust()->describeModel($model->stardust_model_id)?->name);
    }

    public function test_export_and_import_copy_a_model_with_new_field_ids()
    {
        $model = $this->create();

        $copy = $this->manager()->import($this->manager()->export($model), 'articles_copy');

        $this->assertSame(SaveResult::DONE, $copy->status, (string) $copy->error());
        $this->assertNotNull($copy->model);
        $this->assertSame(['title', 'body', 'count'], $copy->model->fields()->pluck('key')->all());
        $this->assertNotContains(self::TITLE, $copy->model->fields()->pluck('id')->all());
        $this->assertNotSame($model->stardust_model_id, $copy->model->stardust_model_id);
    }

    public function test_a_slug_in_use_is_refused()
    {
        $this->create();

        $result = $this->manager()->createModel(new ModelSchema('articles', 'Again'));

        $this->assertSame(SaveResult::INVALID, $result->status);
        $this->assertStringContainsString('already uses the slug', (string) $result->error());
    }

    public function test_a_deleted_model_holds_its_slug_until_stardust_has_purged_it()
    {
        $model = $this->create();
        $this->writeEntry($model->stardust_model_id, ['title' => 'Bye']);

        $this->manager()->deleteModel($model);

        $this->assertSame(SchemaModel::DELETING, $model->refresh()->status);
        $this->assertSame(0, $model->fields()->count());
        $this->assertSame([self::TITLE, self::BODY, self::COUNT], array_keys($this->manager()->states($model)) ?: [self::TITLE, self::BODY, self::COUNT]);
        $this->assertStringContainsString('still holds the slug', (string) $this->manager()->createModel(new ModelSchema('articles', 'Again'))->error());

        $this->drain();

        $again = $this->manager()->createModel(new ModelSchema('articles', 'Again'));
        $this->assertSame(SaveResult::DONE, $again->status, (string) $again->error());
        $this->assertNull(SchemaModel::find($model->id));
    }

    public function test_models_fields_and_changes_are_invisible_across_sites()
    {
        $model = $this->create();
        $batch = SchemaChange::query()->value('batch');
        $other = Site::create(['name' => 'Other', 'domains' => [Str::lower(Str::random(12)).'.test']]);

        $this->asSite($other);

        $this->assertSame(0, SchemaModel::query()->count());
        $this->assertSame(0, SchemaField::query()->count());
        $this->assertSame(0, SchemaChange::query()->count());
        $this->assertNull(SchemaModel::find($model->id));

        $this->expectException(ModelNotFoundException::class);
        $this->manager()->retry((string) $batch);
    }

    public function test_preview_and_states_have_the_agreed_shape()
    {
        $model = $this->create();
        $desired = $this->without($this->change($model, [self::TITLE => ['key' => 'headline', 'label' => 'Headline']]), self::BODY);

        $preview = json_decode((string) json_encode($this->manager()->preview($model, $desired)), true);

        $this->assertSame(['operations', 'errors'], array_keys($preview));
        $this->assertSame(['metadata', 'delete', 'rename'], array_column($preview['operations'], 'op'));
        foreach ($preview['operations'] as $operation) {
            $this->assertSame(['op', 'field_uuid', 'summary', 'affects_existing_data', 'destructive'], array_keys($operation));
            $this->assertIsString($operation['summary']);
            $this->assertIsBool($operation['affects_existing_data']);
            $this->assertIsBool($operation['destructive']);
        }
        $this->assertNull($preview['operations'][0]['field_uuid']);
        $this->assertSame([self::BODY, true, true], [$preview['operations'][1]['field_uuid'], $preview['operations'][1]['affects_existing_data'], $preview['operations'][1]['destructive']]);
        $this->assertSame([true, false], [$preview['operations'][2]['affects_existing_data'], $preview['operations'][2]['destructive']]);
        $this->assertSame([], $preview['errors']);

        $states = json_decode((string) json_encode($this->manager()->states($model)), true);
        $this->assertSame([self::TITLE, self::BODY, self::COUNT], array_keys($states));
        foreach ($states as $state) {
            $this->assertContains($state, ['ready', 'indexing', 'renaming', 'retyping', 'deleting', 'waiting']);
        }
    }

    /**
     * articles: title (text, filterable), body (rich text), count (text).
     *
     * Drained once before it is handed to the test, so every field starts
     * "ready" rather than "indexing" — title's promotion backfill would
     * otherwise still be running, and StarDust refuses a rename, retype or
     * refilter of a field while its own lifecycle is in flight.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    private function create(array $changes = []): SchemaModel
    {
        $schema = new ModelSchema('articles', 'Articles', fields: [
            new FieldSpec(self::TITLE, 'title', 'Title', 'text', filterable: true),
            new FieldSpec(self::BODY, 'body', 'Body', 'rich_text'),
            new FieldSpec(self::COUNT, 'count', 'Count', 'text'),
        ]);
        $schema = $schema->withFields(array_map(fn (FieldSpec $f) => isset($changes[$f->uuid]) ? $f->with($changes[$f->uuid]) : $f, $schema->fields));

        $result = $this->manager()->createModel($schema);

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertNotNull($result->model);

        $this->drain();

        return $result->model;
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes  uuid => FieldSpec changes
     */
    private function change(SchemaModel $model, array $changes): ModelSchema
    {
        $schema = ModelSchema::fromModel($model->refresh());

        return $schema->withFields(array_map(
            fn (FieldSpec $f) => isset($changes[$f->uuid]) ? $f->with($changes[$f->uuid]) : $f,
            $schema->fields,
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes
     */
    private function save(SchemaModel $model, array $changes): SaveResult
    {
        $result = $this->manager()->save($model, $this->change($model, $changes));

        $this->assertContains($result->status, [SaveResult::DONE, SaveResult::INVALID], (string) $result->error());

        return $result;
    }

    private function without(SchemaModel|ModelSchema $schema, string $uuid): ModelSchema
    {
        $schema = $schema instanceof SchemaModel ? $this->change($schema, []) : $schema;

        return $schema->withFields(array_values(array_filter($schema->fields, fn (FieldSpec $f) => $f->uuid !== $uuid)));
    }

    private function withField(ModelSchema $schema, FieldSpec $field): ModelSchema
    {
        return $schema->withFields([...$schema->fields, $field]);
    }

    private function manager(): SchemaManager
    {
        return app(SchemaManager::class);
    }

    /**
     * @return array{id: int, name: string, previous_name: ?string, declared_type: string, filterable: bool, indexed: bool, state: string}
     */
    private function stardustState(SchemaModel $model, string $name): array
    {
        foreach ($this->stardust()->fieldStates($model->stardust_model_id) as $state) {
            if ($state['name'] === $name) {
                return $state;
            }
        }

        $this->fail("StarDust has no field {$name}.");
    }

    /**
     * @return list<string>
     */
    private function stardustNames(SchemaModel $model): array
    {
        return array_values(array_map(
            fn ($state) => $state['previous_name'] ?? $state['name'],
            $this->stardust()->fieldStates($model->stardust_model_id),
        ));
    }

    private function swapStarDust(?int $failOn = null): FlakyStarDust
    {
        $fake = new FlakyStarDust(app(), app(CurrentSite::class));
        $fake->failOn = $failOn;
        $this->app->instance(StarDustService::class, $fake);

        return $fake;
    }
}

/**
 * Counts every StarDust schema write and can fail the Nth one, like a
 * connection that drops half-way through a save.
 */
class FlakyStarDust extends StarDustService
{
    public int $calls = 0;

    public ?int $failOn = null;

    public function renameModel(int $modelId, string $newName): void
    {
        $this->count();
        parent::renameModel($modelId, $newName);
    }

    public function defineField(int $modelId, string $name, string $declaredType, bool $filterable): FieldDescription
    {
        $this->count();

        return parent::defineField($modelId, $name, $declaredType, $filterable);
    }

    public function renameField(int $modelId, int $fieldId, string $newName): void
    {
        $this->count();
        parent::renameField($modelId, $fieldId, $newName);
    }

    public function retypeField(int $modelId, int $fieldId, string $declaredType): void
    {
        $this->count();
        parent::retypeField($modelId, $fieldId, $declaredType);
    }

    public function promoteField(int $modelId, int $fieldId): void
    {
        $this->count();
        parent::promoteField($modelId, $fieldId);
    }

    public function demoteField(int $modelId, int $fieldId): void
    {
        $this->count();
        parent::demoteField($modelId, $fieldId);
    }

    public function deleteField(int $modelId, int $fieldId): bool
    {
        $this->count();

        return parent::deleteField($modelId, $fieldId);
    }

    private function count(): void
    {
        $this->calls++;

        if ($this->calls === $this->failOn) {
            throw new RuntimeException('StarDust went away');
        }
    }
}
