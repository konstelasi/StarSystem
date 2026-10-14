<?php

namespace Tests\Feature\Entries;

use App\Entries\EntryManager;
use App\Models\SchemaModel;
use App\Schema\ModelSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
