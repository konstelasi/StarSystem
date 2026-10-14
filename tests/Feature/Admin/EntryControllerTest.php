<?php

namespace Tests\Feature\Admin;

use App\Entries\EntryManager;
use App\Files\FileStore;
use App\Models\SchemaModel;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\FakeUploads;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * Requests go through the test site's own domain, as in ModelBuilderTest:
 * multisite is on, so ResolveSite picks UsesStarDust's tenant from the Host.
 */
class EntryControllerTest extends TestCase
{
    use CrossSite, FakeUploads, ManagesTestModels, RefreshDatabase, UsesStarDust;

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);
    }

    /**
     * StarDust commits on its own connection, so what these tests queue
     * (the trash field's promotion, entries to index) outlives the
     * test's rollback. Finishing it here keeps later tests' ticks idle.
     */
    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $model = $this->create('articles', 'Articles');

        $this->get($this->url('admin.models.entries.index', ['model' => $model->id]))->assertRedirect(route('login'));
    }

    public function test_the_newest_entry_comes_first()
    {
        $model = $this->create('articles', 'Articles');
        $first = $this->writeEntry($model->stardust_model_id, ['title' => 'First']);
        $second = $this->writeEntry($model->stardust_model_id, ['title' => 'Second']);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/entries/Index')
                ->where('entries.0.id', $second)
                ->where('entries.0.values.title', 'Second')
                ->where('entries.1.id', $first)
                ->where('nextCursor', null)
            );
    }

    public function test_entries_come_one_page_at_a_time()
    {
        $model = $this->create('articles', 'Articles');
        $ids = $this->writeEntries($model, EntryManager::PAGE_SIZE + 1);

        $user = User::factory()->create();

        $cursor = $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', EntryManager::PAGE_SIZE)
                ->where('entries.0.id', end($ids))
                ->whereType('nextCursor', 'string')
            )
            ->inertiaProps('nextCursor');

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $model->id, 'cursor' => $cursor]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.id', $ids[0])
                ->where('nextCursor', null)
            );
    }

    public function test_a_cursor_stardust_did_not_issue_is_not_found()
    {
        $model = $this->create('articles', 'Articles');

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id, 'cursor' => 'not-a-cursor']))
            ->assertNotFound();
    }

    public function test_another_sites_model_id_is_not_found()
    {
        $model = $this->create('articles', 'Articles');
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id], $other))
            ->assertNotFound();
    }

    public function test_storing_an_entry_creates_it_and_returns_to_the_list()
    {
        $model = $this->create('articles', 'Articles');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.entries.store', ['model' => $model->id]), ['fields' => ['title' => 'Hello']])
            ->assertRedirect($this->url('admin.models.entries.index', ['model' => $model->id]));

        $rows = app(EntryManager::class)->page($model)->rows;
        $this->assertCount(1, $rows);
        $this->assertSame('Hello', $rows[0]->fields['title']);
    }

    public function test_editing_shows_the_stored_values_of_the_models_fields()
    {
        $model = $this->create('articles', 'Articles');
        $entryId = app(EntryManager::class)->create($model, ['title' => 'Hello', 'body' => 'World']);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.edit', ['model' => $model->id, 'entry' => $entryId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/entries/Edit')
                ->where('entry.id', $entryId)
                ->where('values.title', 'Hello')
                ->where('values.body', 'World')
                ->missing('values.'.EntryManager::TRASH_FIELD)
                ->has('schema.fields', 2)
            );
    }

    public function test_updating_saves_the_values_and_keeps_the_entry_active()
    {
        $model = $this->create('articles', 'Articles');
        $entryId = app(EntryManager::class)->create($model, ['title' => 'Hello', 'body' => 'World']);

        $this->actingAs(User::factory()->create())
            ->put($this->url('admin.models.entries.update', ['model' => $model->id, 'entry' => $entryId]), ['fields' => ['title' => 'Changed', 'body' => 'World']])
            ->assertRedirect($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertInertiaFlash('toast.message', 'Entry saved.');

        $entry = app(EntryManager::class)->get($model, $entryId);
        $this->assertSame('Changed', $entry?->fields['title']);
        $this->assertSame(0, $entry?->fields[EntryManager::TRASH_FIELD]);
    }

    public function test_updating_reports_errors_per_field()
    {
        $model = $this->create('articles', 'Articles');
        $entryId = app(EntryManager::class)->create($model, ['title' => 'Hello']);

        $this->actingAs(User::factory()->create())
            ->put($this->url('admin.models.entries.update', ['model' => $model->id, 'entry' => $entryId]), ['fields' => ['title' => str_repeat('x', 5000)]])
            ->assertSessionHasErrors('fields.title');

        $this->assertSame('Hello', app(EntryManager::class)->get($model, $entryId)?->fields['title']);
    }

    public function test_an_entry_of_another_model_or_site_is_not_found()
    {
        $model = $this->create('articles', 'Articles');
        $other = $this->create('pages', 'Pages');
        $entryId = app(EntryManager::class)->create($model, ['title' => 'Hello']);
        $otherSite = $this->makeSite(Str::lower(Str::random(12)).'.test');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.edit', ['model' => $other->id, 'entry' => $entryId]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.edit', ['model' => $model->id, 'entry' => $entryId + 1000]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.edit', ['model' => $model->id, 'entry' => $entryId], $otherSite))
            ->assertNotFound();

        $this->actingAs($user)
            ->put($this->url('admin.models.entries.update', ['model' => $other->id, 'entry' => $entryId]), ['fields' => ['title' => 'Hijacked']])
            ->assertNotFound();
    }

    public function test_a_trashed_entry_leaves_the_list_for_the_trash_before_the_tick()
    {
        $model = $this->create('articles', 'Articles');
        [$kept, $trashed] = $this->createAndTrash($model);

        // Not indexed yet: the list falls back to filtering in PHP.
        $this->assertFalse(app(EntryManager::class)->trashIndexed($model));
        $this->assertLists($model, active: [$kept], trash: [$trashed]);
    }

    public function test_a_trashed_entry_leaves_the_list_for_the_trash_once_indexed()
    {
        $model = $this->create('articles', 'Articles');
        [$kept, $trashed] = $this->createAndTrash($model);

        $this->drain();

        $this->assertTrue(app(EntryManager::class)->trashIndexed($model));
        $this->assertLists($model, active: [$kept], trash: [$trashed]);
    }

    public function test_moving_to_trash_offers_undo()
    {
        $model = $this->create('articles', 'Articles');
        $entryId = app(EntryManager::class)->create($model, ['title' => 'Hello']);
        $restoreUrl = route('admin.models.entries.restore', ['model' => $model, 'entry' => $entryId], absolute: false);

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.entries.trash.store', ['model' => $model->id, 'entry' => $entryId]))
            ->assertRedirect($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertInertiaFlash('toast.message', 'Moved to trash.')
            ->assertInertiaFlash('toast.action.label', 'Undo')
            ->assertInertiaFlash('toast.action.url', $restoreUrl);
    }

    public function test_restoring_brings_an_entry_back()
    {
        $model = $this->create('articles', 'Articles');
        [$kept, $trashed] = $this->createAndTrash($model);

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.entries.restore', ['model' => $model->id, 'entry' => $trashed]))
            ->assertRedirect($this->url('admin.models.entries.trash', ['model' => $model->id]))
            ->assertInertiaFlash('toast.message', 'Entry restored.');

        $this->assertLists($model, active: [$trashed, $kept], trash: []);
    }

    public function test_a_trashed_entry_must_be_restored_before_editing()
    {
        $model = $this->create('articles', 'Articles');
        [, $trashed] = $this->createAndTrash($model);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.edit', ['model' => $model->id, 'entry' => $trashed]))
            ->assertRedirect($this->url('admin.models.entries.trash', ['model' => $model->id]))
            ->assertInertiaFlash('toast.message', 'Restore this entry to edit it.');

        $this->actingAs($user)
            ->put($this->url('admin.models.entries.update', ['model' => $model->id, 'entry' => $trashed]), ['fields' => ['title' => 'Changed']])
            ->assertRedirect($this->url('admin.models.entries.trash', ['model' => $model->id]));

        $this->assertSame('Second', app(EntryManager::class)->get($model, $trashed)?->fields['title']);
    }

    public function test_deleting_for_good_removes_a_trashed_entry()
    {
        $model = $this->create('articles', 'Articles');
        [$kept, $trashed] = $this->createAndTrash($model);

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.entries.destroy', ['model' => $model->id, 'entry' => $trashed]))
            ->assertRedirect($this->url('admin.models.entries.trash', ['model' => $model->id]))
            ->assertInertiaFlash('toast.message', 'Entry deleted for good.');

        $this->assertNull(app(EntryManager::class)->get($model, $trashed));
        $this->assertLists($model, active: [$kept], trash: []);
    }

    public function test_an_active_entry_must_go_through_the_trash_first()
    {
        $model = $this->create('articles', 'Articles');
        $entryId = app(EntryManager::class)->create($model, ['title' => 'Hello']);

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.entries.destroy', ['model' => $model->id, 'entry' => $entryId]))
            ->assertRedirect($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertInertiaFlash('toast.message', 'Move it to the trash first.');

        $this->assertNotNull(app(EntryManager::class)->get($model, $entryId));
    }

    public function test_another_sites_entry_cannot_be_deleted()
    {
        $model = $this->create('articles', 'Articles');
        [, $trashed] = $this->createAndTrash($model);
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->delete($this->url('admin.models.entries.destroy', ['model' => $model->id, 'entry' => $trashed], $other))
            ->assertNotFound();

        // The request resolved the other site; check as the owner again.
        app(CurrentSite::class)->set($this->site);
        $this->assertNotNull(app(EntryManager::class)->get($model, $trashed));
    }

    public function test_the_list_sends_field_specs_and_the_names_of_its_files()
    {
        Storage::fake(config()->string('files.disk'));
        $model = app(SchemaManager::class)->createModel(new ModelSchema('people', 'People', fields: [
            new FieldSpec((string) Str::uuid(), 'name', 'Name', 'text'),
            new FieldSpec((string) Str::uuid(), 'photos', 'Photos', 'file', settings: ['multiple' => true]),
        ]))->model;
        $this->assertNotNull($model);

        $ours = app(FileStore::class)->store($this->png('team-photo.png'));

        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');
        app(CurrentSite::class)->set($other);
        $theirs = app(FileStore::class)->store($this->png('secret.png'));
        app(CurrentSite::class)->set($this->site);

        $entryId = app(EntryManager::class)->create($model, ['name' => 'Ada', 'photos' => [$ours->uuid, $theirs->uuid]]);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.1.type', 'file')
                ->where('columns.1.settings.multiple', true)
                ->where('entries.0.id', $entryId)
                ->where('entries.0.file_names', [$ours->uuid => 'team-photo.png'])
            );
    }

    /**
     * Two entries; the second is moved to the trash through the admin.
     *
     * @return array{int, int} The kept entry's id, then the trashed one's.
     */
    private function createAndTrash(SchemaModel $model): array
    {
        $kept = app(EntryManager::class)->create($model, ['title' => 'First']);
        $trashed = app(EntryManager::class)->create($model, ['title' => 'Second']);

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.entries.trash.store', ['model' => $model->id, 'entry' => $trashed]))
            ->assertRedirect();

        return [$kept, $trashed];
    }

    /**
     * @param  list<int>  $active  Expected ids in the list, newest first.
     * @param  list<int>  $trash  Expected ids in the trash, newest first.
     */
    private function assertLists(SchemaModel $model, array $active, array $trash): void
    {
        $user = User::factory()->create();

        $listed = $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page->where('view', 'active'))
            ->inertiaProps('entries');
        $this->assertSame($active, array_column($listed, 'id'));

        $trashed = $this->actingAs($user)
            ->get($this->url('admin.models.entries.trash', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page->where('view', 'trash'))
            ->inertiaProps('entries');
        $this->assertSame($trash, array_column($trashed, 'id'));

        foreach ($trashed as $row) {
            $this->assertNotNull($row['trashed_at']);
        }
    }

    /**
     * @return list<int> The entry ids, oldest first.
     */
    private function writeEntries(SchemaModel $model, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->writeEntry($model->stardust_model_id, ['title' => "Entry {$i}"]);
        }

        return $ids;
    }
}
