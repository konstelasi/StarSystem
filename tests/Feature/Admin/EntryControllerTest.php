<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Entries\EntryManager;
use App\Files\FileStore;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteUser;
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

    public function test_a_new_entry_records_the_signed_in_user_as_its_author()
    {
        $model = $this->create('articles', 'Articles');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post($this->url('admin.models.entries.store', ['model' => $model->id]), ['fields' => ['title' => 'Hello']]);

        // A list read carries the creator, which is registered, and only a
        // point read carries who saved last.
        $row = app(EntryManager::class)->page($model)->rows[0];
        $this->assertSame($user->id, app(EntryManager::class)->createdBy($row));

        $entry = app(EntryManager::class)->get($model, $row->id);
        $this->assertSame($user->id, app(EntryManager::class)->createdBy($entry));
        $this->assertSame($user->id, app(EntryManager::class)->updatedBy($entry));
    }

    public function test_saving_an_entry_records_who_saved_it_and_keeps_who_made_it()
    {
        $model = $this->create('articles', 'Articles');
        $maker = User::factory()->create();
        $editor = User::factory()->create();
        $entryId = app(EntryManager::class)->create($model, ['title' => 'Hello'], $maker->id);

        $this->actingAs($editor)
            ->put($this->url('admin.models.entries.update', ['model' => $model->id, 'entry' => $entryId]), ['fields' => ['title' => 'Changed']]);

        $entry = app(EntryManager::class)->get($model, $entryId);
        $this->assertSame($maker->id, app(EntryManager::class)->createdBy($entry));
        $this->assertSame($editor->id, app(EntryManager::class)->updatedBy($entry));
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

    public function test_the_list_says_whether_the_model_has_fields()
    {
        $user = User::factory()->create();
        $empty = app(SchemaManager::class)->createModel(new ModelSchema('drafts', 'Drafts'))->model;
        $this->assertNotNull($empty);
        $filled = $this->create('articles', 'Articles');

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $empty->id]))
            ->assertInertia(fn (Assert $page) => $page->where('hasFields', false)->where('columns', []));

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $filled->id]))
            ->assertInertia(fn (Assert $page) => $page->where('hasFields', true));
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

    public function test_the_list_names_linked_entries()
    {
        [$authors, $posts] = $this->authorsAndPosts();
        $ada = app(EntryManager::class)->create($authors, ['title' => 'Ada']);
        $grace = app(EntryManager::class)->create($authors, ['title' => 'Grace']);
        app(EntryManager::class)->create($posts, ['title' => 'Hello', 'author' => $ada, 'tags' => [$ada, $grace]]);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $posts->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("entries.0.links.author.{$ada}.label", 'Ada')
                ->where("entries.0.links.author.{$ada}.state", 'ok')
                ->where("entries.0.links.tags.{$ada}.label", 'Ada')
                ->where("entries.0.links.tags.{$grace}.label", 'Grace')
                ->has('entries.0.links.author', 1)
                ->has('entries.0.links.tags', 2)
            );

        // A model without relation columns has nothing to name.
        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $authors->id]))
            ->assertInertia(fn (Assert $page) => $page->where('entries.0.links', []));
    }

    public function test_a_trashed_linked_entry_is_marked()
    {
        [$authors, $posts] = $this->authorsAndPosts();
        $grace = app(EntryManager::class)->create($authors, ['title' => 'Grace']);
        app(EntryManager::class)->create($posts, ['title' => 'Hello', 'author' => $grace]);
        app(EntryManager::class)->trash($authors, app(EntryManager::class)->get($authors, $grace));

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $posts->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("entries.0.links.author.{$grace}.label", 'Grace (in trash)')
                ->where("entries.0.links.author.{$grace}.state", 'trashed')
            );
    }

    public function test_a_deleted_linked_entry_is_shown_as_deleted()
    {
        [$authors, $posts] = $this->authorsAndPosts();
        $grace = app(EntryManager::class)->create($authors, ['title' => 'Grace']);
        app(EntryManager::class)->create($posts, ['title' => 'Hello', 'author' => $grace]);
        $entry = app(EntryManager::class)->get($authors, $grace);
        app(EntryManager::class)->trash($authors, $entry);
        app(EntryManager::class)->delete($authors, $entry);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $posts->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("entries.0.links.author.{$grace}.label", 'Deleted entry')
                ->where("entries.0.links.author.{$grace}.state", 'missing')
            );
    }

    public function test_links_to_a_model_being_deleted_are_shown_as_deleted()
    {
        [$authors, $posts] = $this->authorsAndPosts();
        $ada = app(EntryManager::class)->create($authors, ['title' => 'Ada']);
        app(EntryManager::class)->create($posts, ['title' => 'Hello', 'author' => $ada]);
        $authors->update(['status' => SchemaModel::DELETING]);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $posts->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("entries.0.links.author.{$ada}.label", 'Deleted entry')
                ->where("entries.0.links.author.{$ada}.state", 'missing')
            );
    }

    public function test_a_cell_resolves_at_most_five_links()
    {
        [$authors, $posts] = $this->authorsAndPosts();
        $ids = array_map(fn (int $n) => app(EntryManager::class)->create($authors, ['title' => "Author {$n}"]), range(1, 7));
        app(EntryManager::class)->create($posts, ['title' => 'Hello', 'tags' => $ids]);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $posts->id]))
            ->assertInertia(function (Assert $page) use ($ids) {
                $page->has('entries.0.links.tags', 5)->where('entries.0.values.tags', $ids);

                foreach (array_slice($ids, 0, 5) as $id) {
                    $page->where("entries.0.links.tags.{$id}.state", 'ok');
                }
            });
    }

    public function test_another_sites_entry_is_never_named()
    {
        [$authors, $posts] = $this->authorsAndPosts();

        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');
        app(CurrentSite::class)->set($other);
        $theirAuthors = $this->create('authors', 'Authors');
        $secret = app(EntryManager::class)->create($theirAuthors, ['title' => 'Secret']);
        app(CurrentSite::class)->set($this->site);

        app(EntryManager::class)->create($posts, ['title' => 'Hello', 'author' => $secret]);

        $response = $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $posts->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("entries.0.links.author.{$secret}.label", 'Deleted entry')
                ->where("entries.0.links.author.{$secret}.state", 'missing')
            );

        $this->assertStringNotContainsString('Secret', (string) $response->getContent());
    }

    public function test_the_edit_page_names_linked_entries()
    {
        [$authors, $posts] = $this->authorsAndPosts();
        $ids = array_map(fn (int $n) => app(EntryManager::class)->create($authors, ['title' => "Author {$n}"]), range(1, 7));
        $entryId = app(EntryManager::class)->create($posts, ['title' => 'Hello', 'author' => $ids[0], 'tags' => $ids]);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.edit', ['model' => $posts->id, 'entry' => $entryId]))
            ->assertInertia(function (Assert $page) use ($ids) {
                // Every link of the form, not the first few of a cell.
                $page->where('values.author', $ids[0])
                    ->has('links.author', 1)
                    ->where("links.author.{$ids[0]}.label", 'Author 1')
                    ->has('links.tags', 7);

                foreach ($ids as $position => $id) {
                    $page->where("links.tags.{$id}.label", 'Author '.($position + 1));
                }
            });
    }

    public function test_two_fields_to_one_model_name_entries_by_their_own_field()
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->create('posts', 'Posts');
        $link = fn (string $key) => ['link' => ['model' => $authors->id, 'label_field' => $this->fieldId($authors, $key)], 'multiple' => false];

        // Both link to Authors. The author is named by the title, the reviewer by the body.
        $schema = ModelSchema::fromModel($posts);
        $result = app(SchemaManager::class)->save($posts, $schema->withFields([
            ...$schema->fields,
            new FieldSpec((string) Str::uuid(), 'author', 'Author', 'relation', $link('title'), shownInList: true),
            new FieldSpec((string) Str::uuid(), 'reviewer', 'Reviewer', 'relation', $link('body'), shownInList: true),
        ]));
        $this->assertTrue($result->succeeded(), (string) $result->error());

        $ada = app(EntryManager::class)->create($authors, ['title' => 'Ada', 'body' => 'ada@example.com']);
        $entryId = app(EntryManager::class)->create($posts, ['title' => 'Hello', 'author' => $ada, 'reviewer' => $ada]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $posts->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("entries.0.links.author.{$ada}.label", 'Ada')
                ->where("entries.0.links.reviewer.{$ada}.label", 'ada@example.com')
            );

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.edit', ['model' => $posts->id, 'entry' => $entryId]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("links.author.{$ada}.label", 'Ada')
                ->where("links.reviewer.{$ada}.label", 'ada@example.com')
            );
    }

    public function test_lookup_needs_view_on_that_model()
    {
        [$authors] = $this->authorsAndPosts();
        app(EntryManager::class)->create($authors, ['title' => 'Ada']);

        $blind = $this->member([], ['posts' => [Ability::ENTRIES_VIEW]]);
        $this->actingAs($blind)->getJson($this->lookupUrl($authors))->assertForbidden();

        $sighted = $this->member([], ['authors' => [Ability::ENTRIES_VIEW]]);
        $this->actingAs($sighted)->getJson($this->lookupUrl($authors))
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Ada')
            ->assertJsonPath('nextCursor', null);
    }

    public function test_lookup_of_another_sites_model_is_not_found()
    {
        [$authors] = $this->authorsAndPosts();
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->getJson($this->url('admin.models.entries.lookup', ['model' => $authors->id, 'label' => $this->fieldId($authors, 'title')], $other))
            ->assertNotFound();
    }

    public function test_lookup_of_a_model_being_deleted_is_not_found()
    {
        [$authors] = $this->authorsAndPosts();
        $authors->update(['status' => SchemaModel::DELETING]);

        $this->actingAs(User::factory()->create())->getJson($this->lookupUrl($authors))->assertNotFound();
    }

    public function test_lookup_searches_by_prefix_once_indexed()
    {
        [$authors] = $this->authorsAndPosts();
        $ada = app(EntryManager::class)->create($authors, ['title' => 'Ada']);
        $adele = app(EntryManager::class)->create($authors, ['title' => 'Adele']);
        app(EntryManager::class)->create($authors, ['title' => 'Grace']);
        $this->makeFilterable($authors, 'title');
        $user = User::factory()->create();

        $this->actingAs($user)->getJson($this->lookupUrl($authors, 'Ad'))
            ->assertOk()
            ->assertJsonPath('searchable', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', ['id' => $adele, 'label' => 'Adele'])
            ->assertJsonPath('data.1', ['id' => $ada, 'label' => 'Ada']);

        // The start of a name only, and a wildcard is just a character.
        $this->actingAs($user)->getJson($this->lookupUrl($authors, 'ele'))->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson($this->lookupUrl($authors, '%'))->assertJsonCount(0, 'data');

        // Without a search it lists everything, newest first.
        $this->actingAs($user)->getJson($this->lookupUrl($authors))->assertJsonCount(3, 'data')->assertJsonPath('data.0.label', 'Grace');
    }

    public function test_lookup_says_when_it_cannot_search()
    {
        [$authors] = $this->authorsAndPosts();
        app(EntryManager::class)->create($authors, ['title' => 'Ada']);
        app(EntryManager::class)->create($authors, ['title' => 'Grace']);

        // The title isn't filterable, so the search text changes nothing.
        $this->actingAs(User::factory()->create())->getJson($this->lookupUrl($authors, 'Ad'))
            ->assertOk()
            ->assertJsonPath('searchable', false)
            ->assertJsonCount(2, 'data');
    }

    public function test_lookup_leaves_out_trashed_entries()
    {
        [$authors] = $this->authorsAndPosts();
        $ada = app(EntryManager::class)->create($authors, ['title' => 'Ada']);
        $grace = app(EntryManager::class)->create($authors, ['title' => 'Grace']);
        app(EntryManager::class)->trash($authors, app(EntryManager::class)->get($authors, $grace));
        $user = User::factory()->create();

        // Before the trash field is indexed the list is cut down in PHP.
        $this->actingAs($user)->getJson($this->lookupUrl($authors))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ada);

        $this->drain();

        $this->actingAs($user)->getJson($this->lookupUrl($authors))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ada);
    }

    public function test_lookup_refuses_a_field_that_cannot_name_entries()
    {
        [$authors, $posts] = $this->authorsAndPosts();
        $user = User::factory()->create();

        // A relation can't name entries, another model's field isn't this one's, and the label is required.
        foreach ([$this->fieldId($posts, 'author'), $this->fieldId($posts, 'title'), (string) Str::uuid()] as $label) {
            $this->actingAs($user)
                ->getJson($this->url('admin.models.entries.lookup', ['model' => $authors->id, 'label' => $label]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('label');
        }

        $this->actingAs($user)
            ->getJson($this->url('admin.models.entries.lookup', ['model' => $authors->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');
    }

    public function test_lookup_cursor_stardust_did_not_issue_is_not_found()
    {
        [$authors] = $this->authorsAndPosts();

        $this->actingAs(User::factory()->create())
            ->getJson($this->url('admin.models.entries.lookup', ['model' => $authors->id, 'label' => $this->fieldId($authors, 'title'), 'cursor' => 'not-a-cursor']))
            ->assertNotFound();
    }

    /**
     * Authors and Posts, both with a title and a body. A post links to one
     * author (the list shows it) and to several tags, which are authors too.
     * Authors are named by their title.
     *
     * @return array{SchemaModel, SchemaModel}
     */
    private function authorsAndPosts(): array
    {
        $authors = $this->create('authors', 'Authors');
        $posts = $this->create('posts', 'Posts');
        $title = (string) $authors->fields()->where('key', 'title')->value('id');
        $link = fn (bool $multiple) => ['link' => ['model' => $authors->id, 'label_field' => $title], 'multiple' => $multiple];

        $schema = ModelSchema::fromModel($posts);
        $result = app(SchemaManager::class)->save($posts, $schema->withFields([
            ...$schema->fields,
            new FieldSpec((string) Str::uuid(), 'author', 'Author', 'relation', $link(false), shownInList: true),
            new FieldSpec((string) Str::uuid(), 'tags', 'Tags', 'relation', $link(true), shownInList: true),
        ]));
        $this->assertTrue($result->succeeded(), (string) $result->error());

        return [$authors, $posts];
    }

    /**
     * The lookup of a model's entries, named by its title.
     */
    private function lookupUrl(SchemaModel $model, ?string $q = null): string
    {
        return $this->url('admin.models.entries.lookup', array_filter([
            'model' => $model->id,
            'label' => $this->fieldId($model, 'title'),
            'q' => $q,
        ]));
    }

    private function fieldId(SchemaModel $model, string $key): string
    {
        return (string) $model->fields()->where('key', $key)->value('id');
    }

    /**
     * Saves the model with that field filterable and lets StarDust index it.
     */
    private function makeFilterable(SchemaModel $model, string $key): void
    {
        $schema = ModelSchema::fromModel($model);
        $result = app(SchemaManager::class)->save($model, $schema->withFields(array_map(
            fn (FieldSpec $spec) => $spec->key === $key
                ? new FieldSpec($spec->uuid, $spec->key, $spec->label, $spec->type, $spec->settings, $spec->helper, $spec->required, true, $spec->shownInList, $spec->layoutSlot)
                : $spec,
            $schema->fields,
        )));
        $this->assertTrue($result->succeeded(), (string) $result->error());

        $this->drain();
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
