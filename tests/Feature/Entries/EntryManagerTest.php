<?php

namespace Tests\Feature\Entries;

use App\Entries\EntryManager;
use App\Models\SchemaModel;
use App\Schema\ModelSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use StarDust\Read\Entry;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class EntryManagerTest extends TestCase
{
    use RefreshDatabase, UsesStarDust;

    public function test_create_writes_the_trash_flag_unset()
    {
        $model = $this->registerModel('articles');

        $entryId = $this->manager()->create($model, ['title' => 'Hello']);
        $entry = $this->manager()->get($model, $entryId);

        $this->assertSame('Hello', $entry->fields['title']);
        $this->assertSame(0, $entry->fields[EntryManager::TRASH_FIELD]);
    }

    public function test_the_trash_field_is_filterable_and_stays_out_of_the_builder()
    {
        $model = $this->registerModel('articles');
        $this->manager()->create($model, ['title' => 'Hello']);

        $this->drain();

        $state = collect($this->stardust()->fieldStates($model->stardust_model_id))
            ->firstWhere('name', '_trashed');

        $this->assertNotNull($state);
        $this->assertTrue($state['filterable']);

        // Never written to ss_fields, so the builder never sees it.
        $this->assertSame([], ModelSchema::fromModel($model->refresh())->fields);
    }

    public function test_a_second_entry_can_be_created_once_the_trash_field_is_filterable()
    {
        $model = $this->registerModel('articles');
        $this->manager()->create($model, ['title' => 'First']);

        $this->drain();

        $entryId = $this->manager()->create($model, ['title' => 'Second']);

        $this->assertSame('Second', $this->manager()->get($model, $entryId)->fields['title']);
    }

    /**
     * The trash field's promotion defers its slot to the next tick. On
     * StarDust 0.3.0-alpha.1 any write before that tick made every later
     * tick fail on `ux_slot_assignments_field_live`, for the whole install.
     */
    public function test_a_second_write_before_the_first_tick_does_not_break_the_tick()
    {
        $created = $this->registerModel('created');
        $this->manager()->create($created, ['title' => 'First']);
        $this->manager()->create($created, ['title' => 'Second']);

        $updated = $this->registerModel('updated');
        $entryId = $this->manager()->create($updated, ['title' => 'First']);
        $this->manager()->update($updated, $entryId, ['title' => 'Changed']);

        $this->drain();

        $this->assertTrue($this->trashIndexed($created));
        $this->assertTrue($this->trashIndexed($updated));
    }

    public function test_update_preserves_the_trash_flag_it_did_not_change()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->manager()->create($model, ['title' => 'Hello']);

        // Trash it directly, bypassing the manager, the way a future
        // trash() action will.
        $this->stardust()->updateEntry($model->stardust_model_id, $entryId, [
            'title' => 'Hello',
            EntryManager::TRASH_FIELD => 1,
        ]);

        $this->manager()->update($model, $entryId, ['title' => 'Changed']);

        $entry = $this->manager()->get($model, $entryId);
        $this->assertSame('Changed', $entry->fields['title']);
        $this->assertSame(1, $entry->fields[EntryManager::TRASH_FIELD]);
    }

    public function test_trash_stores_when_and_restore_clears_it()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->manager()->create($model, ['title' => 'Hello']);

        $this->manager()->trash($model, $this->manager()->get($model, $entryId));

        $entry = $this->manager()->get($model, $entryId);
        $this->assertTrue($this->manager()->isTrashed($entry));
        $this->assertEqualsWithDelta(now()->getTimestamp(), $entry->fields[EntryManager::TRASH_FIELD], 5);
        $this->assertSame('Hello', $entry->fields['title']);

        // An edit made while trashed keeps the time it was trashed.
        $this->manager()->update($model, $entryId, ['title' => 'Changed']);
        $this->assertSame($entry->fields[EntryManager::TRASH_FIELD], $this->manager()->get($model, $entryId)->fields[EntryManager::TRASH_FIELD]);

        $this->manager()->restore($model, $this->manager()->get($model, $entryId));

        $restored = $this->manager()->get($model, $entryId);
        $this->assertFalse($this->manager()->isTrashed($restored));
        $this->assertSame(0, $restored->fields[EntryManager::TRASH_FIELD]);

        $this->drain();
    }

    public function test_an_authored_entry_remembers_who_made_it_and_who_saved_it_last()
    {
        $model = $this->registerModel('articles');

        $entryId = $this->manager()->create($model, ['title' => 'Hello'], 7);
        $entry = $this->manager()->get($model, $entryId);

        $this->assertSame(7, $this->manager()->createdBy($entry));
        $this->assertSame(7, $this->manager()->updatedBy($entry));
        $this->assertSame(0, $entry->fields[EntryManager::TRASH_FIELD]);

        $this->drain();
    }

    public function test_an_entry_made_without_a_user_has_no_author()
    {
        $model = $this->registerModel('articles');

        $entry = $this->manager()->get($model, $this->manager()->create($model, ['title' => 'Hello']));

        $this->assertNull($this->manager()->createdBy($entry));
        $this->assertNull($this->manager()->updatedBy($entry));
        $this->assertArrayNotHasKey(EntryManager::CREATED_BY_FIELD, $entry->fields);

        $this->drain();
    }

    public function test_the_creator_field_is_filterable_and_stays_out_of_the_builder()
    {
        $model = $this->registerModel('articles');
        $this->manager()->create($model, ['title' => 'Hello'], 7);

        $this->drain();

        $states = collect($this->stardust()->fieldStates($model->stardust_model_id));
        $this->assertTrue($states->firstWhere('name', EntryManager::CREATED_BY_FIELD)['filterable']);
        $this->assertNull($states->firstWhere('name', EntryManager::UPDATED_BY_FIELD));
        $this->assertSame([], ModelSchema::fromModel($model->refresh())->fields);
    }

    public function test_an_edit_keeps_who_made_it_and_records_who_saved_it()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->manager()->create($model, ['title' => 'Hello'], 7);

        $this->manager()->update($model, $entryId, ['title' => 'Changed'], 9);

        $entry = $this->manager()->get($model, $entryId);
        $this->assertSame('Changed', $entry->fields['title']);
        $this->assertSame(7, $this->manager()->createdBy($entry));
        $this->assertSame(9, $this->manager()->updatedBy($entry));

        $this->drain();
    }

    public function test_an_edit_with_no_user_leaves_both_as_they_were()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->manager()->create($model, ['title' => 'Hello'], 7);
        $this->manager()->update($model, $entryId, ['title' => 'Second'], 9);

        $this->manager()->update($model, $entryId, ['title' => 'Third']);

        $entry = $this->manager()->get($model, $entryId);
        $this->assertSame(7, $this->manager()->createdBy($entry));
        $this->assertSame(9, $this->manager()->updatedBy($entry));

        $this->drain();
    }

    public function test_editing_an_entry_that_had_no_author_does_not_give_it_one()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->manager()->create($model, ['title' => 'Old']);

        $this->manager()->update($model, $entryId, ['title' => 'Changed'], 9);

        $entry = $this->manager()->get($model, $entryId);
        $this->assertNull($this->manager()->createdBy($entry));
        $this->assertSame(9, $this->manager()->updatedBy($entry));

        $this->drain();
    }

    public function test_trashing_and_restoring_keep_who_made_and_saved_it()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->manager()->create($model, ['title' => 'Hello'], 7);
        $this->manager()->update($model, $entryId, ['title' => 'Changed'], 9);

        $this->manager()->trash($model, $this->manager()->get($model, $entryId));
        $trashed = $this->manager()->get($model, $entryId);
        $this->assertSame([7, 9], [$this->manager()->createdBy($trashed), $this->manager()->updatedBy($trashed)]);

        $this->manager()->restore($model, $trashed);
        $restored = $this->manager()->get($model, $entryId);
        $this->assertSame([7, 9], [$this->manager()->createdBy($restored), $this->manager()->updatedBy($restored)]);

        $this->drain();
    }

    /**
     * Two reserved fields now wait for the first tick. Writing again before
     * it must still leave the tick able to finish.
     */
    public function test_writes_before_the_first_tick_do_not_break_it_with_two_fields_waiting()
    {
        $model = $this->registerModel('articles');
        $first = $this->manager()->create($model, ['title' => 'First'], 7);
        $this->manager()->create($model, ['title' => 'Second'], 8);
        $this->manager()->update($model, $first, ['title' => 'Changed'], 9);

        $this->drain();

        $this->assertTrue($this->trashIndexed($model));
        $this->assertTrue($this->stardust()->describeModel($model->stardust_model_id)?->field(EntryManager::CREATED_BY_FIELD)?->isIndexed ?? false);
    }

    public function test_an_author_is_read_only_from_a_whole_number()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->manager()->create($model, ['title' => 'Hello'], 7);
        $entry = $this->manager()->get($model, $entryId);

        $withText = new Entry($entry->id, $entry->tenantId, $entry->modelId, [EntryManager::CREATED_BY_FIELD => '12', EntryManager::UPDATED_BY_FIELD => 'abc'], $entry->createdAt, null);

        $this->assertSame(12, $this->manager()->createdBy($withText));
        $this->assertNull($this->manager()->updatedBy($withText));

        $this->drain();
    }

    private function manager(): EntryManager
    {
        return app(EntryManager::class);
    }

    /**
     * Whether the model's trash field ended up indexed, the state a
     * working tick leaves it in.
     */
    private function trashIndexed(SchemaModel $model): bool
    {
        return $this->stardust()->describeModel($model->stardust_model_id)?->field(EntryManager::TRASH_FIELD)?->isIndexed ?? false;
    }
}
