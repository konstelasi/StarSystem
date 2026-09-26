<?php

namespace Tests\Feature\Entries;

use App\Entries\EntryManager;
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
}
