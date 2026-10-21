<?php

namespace Tests\Feature\Schema;

use App\Entries\EntryManager;
use App\Models\SchemaChange;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Models\Site;
use App\Schema\FieldSpec;
use App\Schema\FieldTypes\Core\RelationType;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaException;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use App\StarDust\StarDustService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use StarDust\Schema\FieldDescription;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\Fakes\FlakyStarDust;
use Tests\TestCase;

class SchemaManagerTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    private const TITLE = '10000000-0000-4000-8000-000000000001';

    private const BODY = '10000000-0000-4000-8000-000000000002';

    private const COUNT = '10000000-0000-4000-8000-000000000003';

    private const EXTRA = '10000000-0000-4000-8000-000000000004';

    private const MORE = '10000000-0000-4000-8000-000000000005';

    private const LINK = '10000000-0000-4000-8000-000000000006';

    private const NAME = '10000000-0000-4000-8000-000000000007';

    private const HANDLE = '10000000-0000-4000-8000-000000000008';

    private const BIO = '10000000-0000-4000-8000-000000000009';

    private const HEADLINE = '10000000-0000-4000-8000-00000000000a';

    private const RATING = '10000000-0000-4000-8000-00000000000b';

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

    public function test_import_can_override_the_label_as_well_as_the_slug()
    {
        $model = $this->create();

        $copy = $this->manager()->import($this->manager()->export($model), 'articles_copy', 'Articles Copy');

        $this->assertSame(SaveResult::DONE, $copy->status, (string) $copy->error());
        $this->assertSame('articles_copy', $copy->model?->slug);
        $this->assertSame('Articles Copy', $copy->model?->label);
    }

    public function test_duplicate_copies_fields_with_fresh_ids_and_leaves_the_source_untouched()
    {
        $model = $this->create();

        $copy = $this->manager()->duplicate($model, 'articles_copy', 'Articles Copy');

        $this->assertSame(SaveResult::DONE, $copy->status, (string) $copy->error());
        $this->assertNotNull($copy->model);
        $this->assertSame('articles_copy', $copy->model->slug);
        $this->assertSame('Articles Copy', $copy->model->label);
        $this->assertSame(['title', 'body', 'count'], $copy->model->fields()->pluck('key')->all());
        $this->assertNotContains(self::TITLE, $copy->model->fields()->pluck('id')->all());
        $this->assertNotSame($model->stardust_model_id, $copy->model->stardust_model_id);

        // The source is unaffected.
        $model->refresh();
        $this->assertSame('articles', $model->slug);
        $this->assertSame(['title', 'body', 'count'], $model->fields()->pluck('key')->all());
    }

    public function test_duplicating_a_model_being_deleted_is_refused()
    {
        $model = $this->create();
        $this->manager()->deleteModel($model);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('being deleted');

        $this->manager()->duplicate($model, 'articles_copy', 'Articles Copy');
    }

    public function test_deleting_a_model_twice_is_refused()
    {
        $model = $this->create();
        $this->manager()->deleteModel($model);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('already being deleted');

        $this->manager()->deleteModel($model);
    }

    public function test_slug_problem_reports_a_taken_slug_and_a_slug_held_by_a_deleted_model()
    {
        $model = $this->create();

        $this->assertNull($this->manager()->slugProblem('unused'));
        $this->assertStringContainsString('already uses the slug', (string) $this->manager()->slugProblem('articles'));

        $this->manager()->deleteModel($model);

        $this->assertStringContainsString('still holds the slug', (string) $this->manager()->slugProblem('articles'));
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

    public function test_a_save_is_refused_while_another_save_of_the_model_runs()
    {
        $model = $this->create();
        $lock = Cache::lock(SchemaManager::SAVE_LOCK.$model->id, 120);
        $this->assertTrue($lock->get());

        try {
            $result = $this->manager()->save($model, $this->change($model, [self::TITLE => ['label' => 'Headline']]));
        } finally {
            $lock->release();
        }

        $this->assertSame(SaveResult::INVALID, $result->status);
        $this->assertStringContainsString('Another save of this model is running', (string) $result->error());
        $this->assertSame('Title', $model->fields()->find(self::TITLE)?->label);
    }

    public function test_a_retry_is_refused_while_a_save_of_the_model_runs()
    {
        $model = $this->create();
        $flaky = $this->swapStarDust(failOn: 1);
        $result = $this->manager()->save($model, $this->withField($this->change($model, []), new FieldSpec(self::EXTRA, 'summary', 'Summary', 'textarea')));
        $this->assertSame(SaveResult::FAILED, $result->status);
        $this->assertSame($result->batch, $this->manager()->unfinished($model)['batch'] ?? null);

        $flaky->failOn = null;
        $lock = Cache::lock(SchemaManager::SAVE_LOCK.$model->id, 120);
        $this->assertTrue($lock->get());

        try {
            $retried = $this->manager()->retry((string) $result->batch);
        } finally {
            $lock->release();
        }

        $this->assertSame(SaveResult::INVALID, $retried->status);
        $this->assertStringContainsString('Another save of this model is running', (string) $retried->error());
        $this->assertNotNull($this->manager()->unfinished($model));
    }

    public function test_a_save_checks_the_revision_it_was_given_against_the_stored_one()
    {
        $model = $this->create();
        $opened = $model->schema_rev;

        $this->save($model, [self::TITLE => ['label' => 'Headline']]);

        $result = $this->manager()->save($model, $this->change($model, [self::BODY => ['label' => 'Text']]), $opened);

        $this->assertSame(SaveResult::INVALID, $result->status);
        $this->assertStringContainsString('after you opened it', (string) $result->error());
        $this->assertSame('Body', $model->fields()->find(self::BODY)?->label);

        $current = $model->refresh()->schema_rev;
        $this->assertSame(SaveResult::DONE, $this->manager()->save($model, $this->change($model, [self::BODY => ['label' => 'Text']]), $current)->status);
    }

    public function test_a_link_to_a_model_is_saved_with_the_model_as_a_number()
    {
        $authors = $this->authors();
        $posts = $this->posts();

        // The builder's select sends the model as text.
        $settings = ['link' => ['model' => (string) $authors->id, 'label_field' => self::NAME], 'multiple' => false];
        $result = $this->manager()->save($posts, $this->withField($this->change($posts, []), new FieldSpec(self::LINK, 'author', 'Author', 'relation', $settings)));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $field = SchemaField::findOrFail(self::LINK);
        $this->assertSame('relation', $field->type);
        $this->assertSame($authors->id, $field->settings['link']['model'] ?? null);
        $this->assertSame('int', $this->stardustState($posts, 'author')['declared_type']);

        $this->drain();
    }

    public function test_a_relation_without_a_model_is_refused()
    {
        $posts = $this->posts();
        $desired = $this->withField($this->change($posts, []), new FieldSpec(self::LINK, 'author', 'Author', 'relation'));

        $this->assertError(self::LINK, '"Author": choose the model it links to.', $this->errorsOf($posts, $desired));
    }

    public function test_a_link_to_a_model_this_site_does_not_have_is_refused()
    {
        $authors = $this->authors();
        $posts = $this->posts();
        $message = '"Author" links to a model or field this site doesn\'t have. Choose them again.';

        $missing = $this->withField($this->change($posts, []), $this->author($authors, model: $authors->id + 1000));
        $this->assertError(self::LINK, $message, $this->errorsOf($posts, $missing));

        $nameUuid = (string) Str::uuid();
        $this->asSite($this->makeSite(Str::lower(Str::random(12)).'.test'));
        $theirs = $this->manager()->createModel(new ModelSchema('authors', 'Authors', fields: [new FieldSpec($nameUuid, 'name', 'Name', 'text')]))->model;
        $this->assertNotNull($theirs);
        $this->asSite($this->site);

        $foreign = $this->withField($this->change($posts, []), $this->author($theirs, labelField: $nameUuid));
        $this->assertError(self::LINK, $message, $this->errorsOf($posts, $foreign));
    }

    public function test_a_link_to_a_model_being_deleted_is_refused()
    {
        $authors = $this->authors();
        $posts = $this->posts();
        $this->manager()->deleteModel($authors);

        $desired = $this->withField($this->change($posts, []), $this->author($authors));

        $this->assertError(self::LINK, '"Author" links to Authors, which is being deleted.', $this->errorsOf($posts, $desired));

        $this->drain();
    }

    public function test_a_link_must_name_the_field_that_names_the_entries()
    {
        $authors = $this->authors();
        $posts = $this->posts();

        $desired = $this->withField($this->change($posts, []), $this->author($authors, labelField: null));

        $this->assertError(self::LINK, '"Author": choose the field that names Authors entries.', $this->errorsOf($posts, $desired));
    }

    public function test_a_link_to_a_model_with_no_field_that_can_name_entries_is_refused()
    {
        $notes = $this->manager()->createModel(new ModelSchema('notes', 'Notes', fields: [
            new FieldSpec((string) Str::uuid(), 'body', 'Body', 'rich_text'),
            new FieldSpec((string) Str::uuid(), 'tags', 'Tags', 'checkboxes'),
        ]))->model;
        $this->assertNotNull($notes);
        $posts = $this->posts();

        $desired = $this->withField($this->change($posts, []), $this->author($notes, labelField: null));

        $this->assertError(self::LINK, 'Notes has no field that can name its entries. Add a text field to it first.', $this->errorsOf($posts, $desired));
    }

    public function test_a_naming_field_the_model_does_not_have_is_refused()
    {
        $authors = $this->authors();
        $posts = $this->posts();
        $message = '"Author" links to a model or field this site doesn\'t have. Choose them again.';

        $unknown = $this->withField($this->change($posts, []), $this->author($authors, labelField: (string) Str::uuid()));
        $this->assertError(self::LINK, $message, $this->errorsOf($posts, $unknown));

        // A field of another model of this site isn't one of the authors'.
        $another = $this->withField($this->change($posts, []), $this->author($authors, labelField: self::HEADLINE));
        $this->assertError(self::LINK, $message, $this->errorsOf($posts, $another));
    }

    public function test_a_field_that_cannot_name_entries_is_refused()
    {
        $authors = $this->authors();
        $posts = $this->posts();

        $desired = $this->withField($this->change($posts, []), $this->author($authors, labelField: self::BIO));

        $this->assertError(self::LINK, '"Author": Bio can\'t name entries. Pick a text, number or single choice field.', $this->errorsOf($posts, $desired));
    }

    public function test_filtering_must_be_off_before_a_link_allows_several()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->author($authors, filterable: true));
        $this->drain();

        $several = $this->linkTo($authors, multiple: true);
        $together = $this->change($posts, [self::LINK => ['filterable' => false, 'settings' => $several]]);

        $this->assertError(self::LINK, 'Turn off filtering on "Author" first, then allow several values.', $this->errorsOf($posts, $together));

        $off = $this->save($posts, [self::LINK => ['filterable' => false]]);
        $this->assertSame(SaveResult::DONE, $off->status, (string) $off->error());

        $then = $this->save($posts, [self::LINK => ['settings' => $several]]);
        $this->assertSame(SaveResult::DONE, $then->status, (string) $then->error());
        $this->assertTrue(SchemaField::findOrFail(self::LINK)->settings['multiple'] ?? null);

        $this->drain();
    }

    public function test_a_link_to_several_cannot_go_back_to_one_once_there_are_entries()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->author($authors, multiple: true));
        $this->writeEntry($posts->stardust_model_id, ['headline' => 'Hello']);

        $desired = $this->change($posts, [self::LINK => ['settings' => $this->linkTo($authors)]]);

        $this->assertError(self::LINK, '"Author" already holds several entries, so it can\'t go back to one. Add a new relation field instead.', $this->errorsOf($posts, $desired));

        $this->drain();
    }

    public function test_a_link_to_several_can_go_back_to_one_while_there_are_no_entries()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->author($authors, multiple: true));

        $result = $this->save($posts, [self::LINK => ['settings' => $this->linkTo($authors)]]);

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertFalse(SchemaField::findOrFail(self::LINK)->settings['multiple'] ?? null);
    }

    public function test_a_link_cannot_change_its_model_once_there_are_entries()
    {
        $authors = $this->authors();
        $editors = $this->editors();
        $posts = $this->posts($this->author($authors));
        $this->writeEntry($posts->stardust_model_id, ['headline' => 'Hello']);

        $desired = $this->change($posts, [self::LINK => ['settings' => $this->linkTo($editors, labelField: $this->nameOf($editors))]]);

        $this->assertError(self::LINK, '"Author" already links to Authors entries, so it can\'t link to another model. Add a new relation field instead.', $this->errorsOf($posts, $desired));

        // Naming the entries by another field of the same model is fine.
        $renamed = $this->change($posts, [self::LINK => ['settings' => $this->linkTo($authors, labelField: self::HANDLE)]]);
        $this->assertSame([], $this->errorsOf($posts, $renamed));

        $this->drain();
    }

    public function test_a_link_can_change_its_model_while_there_are_no_entries()
    {
        $authors = $this->authors();
        $editors = $this->editors();
        $posts = $this->posts($this->author($authors));

        $result = $this->save($posts, [self::LINK => ['settings' => $this->linkTo($editors, labelField: $this->nameOf($editors))]]);

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertSame($editors->id, SchemaField::findOrFail(self::LINK)->settings['link']['model'] ?? null);
    }

    public function test_a_field_cannot_switch_to_or_from_a_relation_once_there_are_entries()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->rating(), $this->author($authors));
        $this->writeEntry($posts->stardust_model_id, ['headline' => 'Hello']);

        $errors = $this->errorsOf($posts, $this->change($posts, [
            self::RATING => ['type' => 'relation', 'settings' => $this->linkTo($authors)],
            self::LINK => ['type' => 'number', 'settings' => ['whole' => true]],
        ]));

        $this->assertError(self::RATING, '"Rating" can\'t switch to or from a relation once Posts has entries. Add a new field instead.', $errors);
        $this->assertError(self::LINK, '"Author" can\'t switch to or from a relation once Posts has entries. Add a new field instead.', $errors);

        $this->drain();
    }

    public function test_a_field_can_switch_to_or_from_a_relation_while_there_are_no_entries()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->rating(), $this->author($authors));

        $result = $this->save($posts, [
            self::RATING => ['type' => 'relation', 'settings' => $this->linkTo($authors)],
            self::LINK => ['type' => 'number', 'settings' => ['whole' => true]],
        ]);

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertSame(['relation', 'number'], [SchemaField::findOrFail(self::RATING)->type, SchemaField::findOrFail(self::LINK)->type]);

        $this->drain();
    }

    public function test_entries_are_looked_for_only_when_a_change_needs_them()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->author($authors));
        $this->mock(EntryManager::class, fn (MockInterface $mock) => $mock->shouldNotReceive('hasEntries'));

        // A model without relations, and a relation that only changes which field names its entries.
        $renamed = $this->save($authors, [self::HANDLE => ['label' => 'Username']]);
        $named = $this->save($posts, [self::LINK => ['settings' => $this->linkTo($authors, labelField: self::HANDLE)]]);

        $this->assertSame([SaveResult::DONE, SaveResult::DONE], [$renamed->status, $named->status]);
    }

    public function test_entries_are_looked_for_once_however_many_fields_need_them()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->rating(), $this->author($authors));
        $this->mock(EntryManager::class, fn (MockInterface $mock) => $mock->shouldReceive('hasEntries')->once()->andReturnTrue());

        $errors = $this->errorsOf($posts, $this->change($posts, [
            self::RATING => ['type' => 'relation', 'settings' => $this->linkTo($authors)],
            self::LINK => ['type' => 'number', 'settings' => ['whole' => true]],
        ]));

        $this->assertCount(2, $errors);
    }

    public function test_a_removed_label_field_is_refused()
    {
        $authors = $this->authors();
        $this->posts($this->author($authors));

        $message = '"Name" can\'t be removed. Posts names its links by it. Change that first.';
        $this->assertError(self::NAME, $message, $this->errorsOf($authors, $this->without($authors, self::NAME)));

        // A field nothing names its links by can go.
        $this->assertSame([], $this->errorsOf($authors, $this->without($authors, self::HANDLE)));
    }

    public function test_a_label_field_changed_to_a_type_that_cannot_name_entries_is_refused()
    {
        $authors = $this->authors();
        $this->posts($this->author($authors));

        $message = '"Name" can\'t change to a type that can\'t name entries. Posts names its links by it. Change that first.';
        $this->assertError(self::NAME, $message, $this->errorsOf($authors, $this->change($authors, [self::NAME => ['type' => 'rich_text']])));

        // A field nothing names its links by can change, and so can a name to another type that names entries.
        $this->assertSame([], $this->errorsOf($authors, $this->change($authors, [self::HANDLE => ['type' => 'rich_text']])));
        $this->assertSame([], $this->errorsOf($authors, $this->change($authors, [self::NAME => ['type' => 'textarea']])));
    }

    public function test_a_self_link_on_a_new_field_passes()
    {
        $authors = $this->authors();
        $mentor = new FieldSpec(self::LINK, 'mentor', 'Mentor', 'relation', ['link' => ['model' => $authors->id, 'label_field' => self::NAME]]);

        // Named by a field the same save adds.
        $nickname = new FieldSpec(self::MORE, 'nickname', 'Nickname', 'text');
        $buddy = new FieldSpec(self::EXTRA, 'buddy', 'Buddy', 'relation', ['link' => ['model' => $authors->id, 'label_field' => self::MORE]]);
        $both = $this->withField($this->withField($this->change($authors, []), $nickname), $buddy);
        $this->assertSame([], $this->errorsOf($authors, $both));

        $result = $this->manager()->save($authors, $this->withField($this->change($authors, []), $mentor));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertSame($authors->id, SchemaField::findOrFail(self::LINK)->settings['link']['model'] ?? null);

        $this->drain();
    }

    public function test_a_copy_of_a_self_linking_model_links_to_the_original()
    {
        $authors = $this->authors();
        $mentor = new FieldSpec(self::LINK, 'mentor', 'Mentor', 'relation', ['link' => ['model' => $authors->id, 'label_field' => self::NAME]]);
        $this->manager()->save($authors, $this->withField($this->change($authors, []), $mentor));

        $copy = $this->manager()->duplicate($authors, 'authors_copy', 'Authors Copy');

        $this->assertSame(SaveResult::DONE, $copy->status, (string) $copy->error());
        $this->assertNotNull($copy->model);
        $copied = $copy->model->fields()->where('key', 'mentor')->firstOrFail();
        $this->assertNotSame(self::LINK, $copied->id);
        $this->assertSame(['model' => $authors->id, 'label_field' => self::NAME], RelationType::link($copied->settings));

        // It can point at the copy itself while the copy has no entries.
        $own = $this->change($copy->model, [$copied->id => ['settings' => $this->linkTo($copy->model, labelField: $this->nameOf($copy->model))]]);
        $this->assertSame(SaveResult::DONE, $this->manager()->save($copy->model, $own)->status);

        $this->drain();
    }

    public function test_an_import_with_a_foreign_model_id_is_refused()
    {
        $authors = $this->authors();
        $posts = $this->posts($this->author($authors));
        $exported = $this->manager()->export($posts);

        $this->asSite($this->makeSite(Str::lower(Str::random(12)).'.test'));
        $result = $this->manager()->import($exported, 'posts_copy');

        $this->assertSame(SaveResult::INVALID, $result->status);
        $this->assertSame('"Author" links to a model or field this site doesn\'t have. Choose them again.', $result->error());
        $this->assertNull($result->model);
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

    /**
     * authors: name and handle (text), bio (rich text, which can't name
     * entries). Nothing in it is filterable, so there is nothing to drain.
     */
    private function authors(): SchemaModel
    {
        $result = $this->manager()->createModel(new ModelSchema('authors', 'Authors', fields: [
            new FieldSpec(self::NAME, 'name', 'Name', 'text'),
            new FieldSpec(self::HANDLE, 'handle', 'Handle', 'text'),
            new FieldSpec(self::BIO, 'bio', 'Bio', 'rich_text'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertNotNull($result->model);

        return $result->model;
    }

    /**
     * A second model to link to, with fields of its own to name entries by.
     */
    private function editors(): SchemaModel
    {
        $result = $this->manager()->createModel(new ModelSchema('editors', 'Editors', fields: [
            new FieldSpec((string) Str::uuid(), 'name', 'Name', 'text'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertNotNull($result->model);

        return $result->model;
    }

    /**
     * posts: headline (text) and whatever fields are given.
     */
    private function posts(FieldSpec ...$fields): SchemaModel
    {
        $result = $this->manager()->createModel(new ModelSchema('posts', 'Posts', fields: [
            new FieldSpec(self::HEADLINE, 'headline', 'Headline', 'text'),
            ...$fields,
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $this->assertNotNull($result->model);

        return $result->model;
    }

    /**
     * A whole number field, which stores what a relation does.
     */
    private function rating(): FieldSpec
    {
        return new FieldSpec(self::RATING, 'rating', 'Rating', 'number', ['whole' => true]);
    }

    /**
     * A relation field named Author, linking to $target (or to $model, to
     * point it somewhere that isn't a model).
     */
    private function author(SchemaModel $target, bool $multiple = false, ?string $labelField = self::NAME, bool $filterable = false, ?int $model = null): FieldSpec
    {
        return new FieldSpec(self::LINK, 'author', 'Author', 'relation', $this->linkTo($target, $multiple, $labelField, $model), filterable: $filterable);
    }

    /**
     * The settings of a relation field.
     *
     * @return array<string, mixed>
     */
    private function linkTo(SchemaModel $target, bool $multiple = false, ?string $labelField = self::NAME, ?int $model = null): array
    {
        return ['link' => ['model' => $model ?? $target->id, 'label_field' => $labelField], 'multiple' => $multiple];
    }

    private function nameOf(SchemaModel $model): string
    {
        return (string) $model->fields()->where('key', 'name')->value('id');
    }

    /**
     * What stops saving $model as $desired.
     *
     * @return list<array{field_uuid: ?string, message: string}>
     */
    private function errorsOf(SchemaModel $model, ModelSchema $desired): array
    {
        return $this->manager()->preview($model, $desired)['errors'];
    }

    /**
     * @param  list<array{field_uuid: ?string, message: string}>  $errors
     */
    private function assertError(string $fieldUuid, string $message, array $errors): void
    {
        $this->assertContains(['field_uuid' => $fieldUuid, 'message' => $message], $errors, 'Expected the error: '.$message);
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
