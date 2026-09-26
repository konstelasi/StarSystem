<?php

namespace Tests\Feature\StarDust;

use App\Models\Site;
use App\Schema\SchemaException;
use App\Sites\CurrentSite;
use App\StarDust\NotOwnedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use StarDust\Exception\EntryNotFoundException;
use StarDust\Read\EntryQuery;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

class EntryOperationsTest extends TestCase
{
    use RefreshDatabase, UsesStarDust;

    public function test_it_writes_and_reads_an_entry()
    {
        $model = $this->registerModel('articles');

        $entryId = $this->stardust()->writeEntry($model->stardust_model_id, ['title' => 'Hello']);
        $entry = $this->stardust()->getEntry($model->stardust_model_id, $entryId);

        $this->assertNotNull($entry);
        $this->assertSame('Hello', $entry->fields['title']);
        $this->assertSame($model->stardust_model_id, $entry->modelId);
    }

    public function test_a_missing_entry_reads_as_null()
    {
        $model = $this->registerModel('articles');

        $this->assertNull($this->stardust()->getEntry($model->stardust_model_id, 999999));
    }

    public function test_reading_an_entry_by_the_wrong_model_reads_as_null()
    {
        $articles = $this->registerModel('articles');
        $pages = $this->registerModel('pages');

        $entryId = $this->stardust()->writeEntry($articles->stardust_model_id, ['title' => 'Hello']);

        $this->assertNull($this->stardust()->getEntry($pages->stardust_model_id, $entryId));
        $this->assertNotNull($this->stardust()->getEntry($articles->stardust_model_id, $entryId));
    }

    public function test_updating_an_entry_is_a_full_replace()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->stardust()->writeEntry($model->stardust_model_id, ['title' => 'Hello', 'body' => 'World']);

        $this->stardust()->updateEntry($model->stardust_model_id, $entryId, ['title' => 'Changed']);

        $entry = $this->stardust()->getEntry($model->stardust_model_id, $entryId);
        $this->assertSame('Changed', $entry->fields['title']);
        $this->assertArrayNotHasKey('body', $entry->fields);
    }

    public function test_updating_a_missing_entry_throws_entry_not_found()
    {
        $model = $this->registerModel('articles');

        $this->expectException(EntryNotFoundException::class);

        $this->stardust()->updateEntry($model->stardust_model_id, 999999, ['title' => 'x']);
    }

    public function test_entry_not_found_is_described_in_plain_language()
    {
        $model = $this->registerModel('articles');

        try {
            $this->stardust()->updateEntry($model->stardust_model_id, 999999, ['title' => 'x']);
            $this->fail('Expected EntryNotFoundException.');
        } catch (EntryNotFoundException $e) {
            $this->assertSame('This entry no longer exists.', SchemaException::describe($e));
            $this->assertTrue(SchemaException::isExpected($e));
        }
    }

    public function test_deleting_an_entry_is_soft_and_idempotent()
    {
        $model = $this->registerModel('articles');
        $entryId = $this->stardust()->writeEntry($model->stardust_model_id, ['title' => 'Hello']);

        $this->assertTrue($this->stardust()->deleteEntry($model->stardust_model_id, $entryId));
        $this->assertNull($this->stardust()->getEntry($model->stardust_model_id, $entryId));

        // Deleting again is a no-op, not an error.
        $this->assertFalse($this->stardust()->deleteEntry($model->stardust_model_id, $entryId));
    }

    public function test_it_lists_a_models_entries()
    {
        $model = $this->registerModel('articles');
        $this->stardust()->writeEntry($model->stardust_model_id, ['title' => 'One']);
        $this->stardust()->writeEntry($model->stardust_model_id, ['title' => 'Two']);

        $page = $this->stardust()->listEntries(new EntryQuery(
            tenantId: $this->site->id,
            modelId: $model->stardust_model_id,
        ));

        $this->assertCount(2, $page->rows);
        $this->assertNull($page->nextCursor);
    }

    public function test_another_sites_model_is_refused_for_every_entry_call()
    {
        $theirs = $this->registerModel('articles');
        $entryId = $this->stardust()->writeEntry($theirs->stardust_model_id, ['title' => 'Mine']);

        $this->asNewSite();

        $this->assertRefused(fn () => $this->stardust()->writeEntry($theirs->stardust_model_id, ['title' => 'Theirs']));
        $this->assertRefused(fn () => $this->stardust()->getEntry($theirs->stardust_model_id, $entryId));
        $this->assertRefused(fn () => $this->stardust()->updateEntry($theirs->stardust_model_id, $entryId, ['title' => 'x']));
        $this->assertRefused(fn () => $this->stardust()->deleteEntry($theirs->stardust_model_id, $entryId));
        $this->assertRefused(fn () => $this->stardust()->listEntries(new EntryQuery(tenantId: 1, modelId: $theirs->stardust_model_id)));
    }

    private function asNewSite(): Site
    {
        $site = Site::create(['name' => 'Other', 'domains' => [Str::lower(Str::random(12)).'.test']]);
        app(CurrentSite::class)->set($site);

        return $site;
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
        } catch (NotOwnedException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected NotOwnedException.');
    }
}
