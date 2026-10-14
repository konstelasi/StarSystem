<?php

namespace Tests\Feature\Entries;

use App\Entries\EntryManager;
use App\Entries\TrashPurger;
use App\Models\SchemaModel;
use App\Models\Site;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class TrashPurgerTest extends TestCase
{
    use RefreshDatabase, UsesStarDust;

    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_entries_trashed_longer_ago_than_the_setting_are_deleted()
    {
        $model = $this->indexedModel('articles');
        $old = $this->trashed($model, daysAgo: 31);
        $recent = $this->trashed($model, daysAgo: 1);
        $active = $this->entries()->create($model, ['title' => 'Active']);

        $this->assertSame(1, app(TrashPurger::class)->purge());

        $this->assertNull($this->entries()->get($model, $old));
        $this->assertNotNull($this->entries()->get($model, $recent));
        $this->assertNotNull($this->entries()->get($model, $active));
    }

    public function test_a_model_whose_trash_is_not_indexed_yet_is_skipped()
    {
        $model = $this->registerModel('articles');
        $old = $this->trashed($model, daysAgo: 31);

        $this->assertFalse($this->entries()->trashIndexed($model));
        $this->assertSame(0, app(TrashPurger::class)->purge());
        $this->assertNotNull($this->entries()->get($model, $old));
    }

    public function test_every_sites_trash_is_emptied_under_that_site()
    {
        $other = Site::create(['name' => 'Other', 'domains' => [Str::lower(Str::random(12)).'.test']]);
        app(CurrentSite::class)->set($other);
        $otherModel = $this->indexedModel('pages');
        $otherOld = $this->trashed($otherModel, daysAgo: 40);

        app(CurrentSite::class)->set($this->site);
        $model = $this->indexedModel('articles');
        $old = $this->trashed($model, daysAgo: 40);

        $this->assertSame(2, app(TrashPurger::class)->purge());

        // The purge put the site it found back.
        $this->assertSame($this->site->id, app(CurrentSite::class)->tenantId());
        $this->assertNull($this->entries()->get($model, $old));

        app(CurrentSite::class)->set($other);
        $this->assertNull($this->entries()->get($otherModel, $otherOld));
        app(CurrentSite::class)->set($this->site);
    }

    public function test_one_run_deletes_at_most_the_limit()
    {
        $model = $this->indexedModel('articles');
        $this->trashed($model, daysAgo: 31);
        $this->trashed($model, daysAgo: 32);
        $this->trashed($model, daysAgo: 33);

        $this->assertSame(2, app(TrashPurger::class)->purge(limit: 2));
        $this->assertSame(1, app(TrashPurger::class)->purge(limit: 2));
        $this->assertSame(0, app(TrashPurger::class)->purge(limit: 2));
    }

    public function test_the_command_runs_the_purge()
    {
        $model = $this->indexedModel('articles');
        $old = $this->trashed($model, daysAgo: 31);

        $this->artisan('entries:purge-trash')
            ->expectsOutputToContain('Deleted 1 entry from the trash.')
            ->assertSuccessful();

        $this->assertNull($this->entries()->get($model, $old));
    }

    /**
     * A model with one entry, ticked until its trash field is indexed.
     */
    private function indexedModel(string $slug): SchemaModel
    {
        $model = $this->registerModel($slug);
        $this->entries()->create($model, ['title' => 'Seed']);
        $this->drain();

        $this->assertTrue($this->entries()->trashIndexed($model));

        return $model;
    }

    /**
     * An entry moved to the trash $daysAgo days ago, written straight to
     * StarDust since the admin only ever trashes at the current time.
     */
    private function trashed(SchemaModel $model, int $daysAgo): int
    {
        $id = $this->entries()->create($model, ['title' => "Trashed {$daysAgo} days ago"]);

        $this->stardust()->updateEntry($model->stardust_model_id, $id, [
            'title' => "Trashed {$daysAgo} days ago",
            EntryManager::TRASH_FIELD => now()->subDays($daysAgo)->getTimestamp(),
        ]);

        return $id;
    }

    private function entries(): EntryManager
    {
        return app(EntryManager::class);
    }
}
