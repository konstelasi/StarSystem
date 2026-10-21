<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Entries\EntryManager;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteUser;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\FieldTypes\Core\RelationType;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\Schema\ModelSchema;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * What a person can do with a relation field depends on whether they can
 * view the model it links to, and what the server will link at all. Requests
 * go through the test site's own domain, as in ModelGrantRoutesTest.
 */
class EntryRelationTest extends TestCase
{
    use CrossSite, ManagesTestModels, RefreshDatabase, UsesStarDust;

    private SchemaModel $authors;

    /** A title, a body, an optional author and several tags, all linking to Authors. */
    private SchemaModel $posts;

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);
        app(FieldTypeRegistry::class)->register(new RelationType);

        $this->authors = $this->create('authors', 'Authors');
        $this->posts = $this->modelLinking('posts', 'Posts', ['author' => [], 'tags' => ['multiple' => true]]);
    }

    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_links_are_hidden_from_a_role_without_view_on_the_target()
    {
        $ada = $this->author('Ada Lovelace');
        $entryId = $this->newPost(['author' => $ada, 'tags' => [$ada]]);
        $reader = $this->member([], ['posts' => [Ability::ENTRIES_VIEW]]);

        $list = $this->actingAs($reader)
            ->get($this->url('admin.models.entries.index', ['model' => $this->posts->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("entries.0.links.{$ada}.label", "Entry {$ada}")
                ->where("entries.0.links.{$ada}.state", 'hidden')
            );

        $edit = $this->actingAs($reader)
            ->get($this->url('admin.models.entries.edit', ['model' => $this->posts->id, 'entry' => $entryId]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("links.{$ada}.label", "Entry {$ada}")
                ->where("links.{$ada}.state", 'hidden')
            );

        // Only the id reaches them, never the name.
        $this->assertStringNotContainsString('Lovelace', (string) $list->getContent());
        $this->assertStringNotContainsString('Lovelace', (string) $edit->getContent());

        // Someone who can view Authors as well gets the name.
        $sighted = $this->member([], ['posts' => [Ability::ENTRIES_VIEW], 'authors' => [Ability::ENTRIES_VIEW]]);
        $this->actingAs($sighted)
            ->get($this->url('admin.models.entries.edit', ['model' => $this->posts->id, 'entry' => $entryId]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("links.{$ada}.label", 'Ada Lovelace')
                ->where("links.{$ada}.state", 'ok')
            );
    }

    public function test_a_hidden_link_keeps_its_stored_value()
    {
        $ada = $this->author('Ada Lovelace');
        $entryId = $this->newPost(['author' => $ada, 'tags' => [$ada]]);
        $editor = $this->member([], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE]]);

        // The form sends nothing for what they can't see, and an update
        // replaces the whole entry, so the server puts the links back.
        $this->actingAs($editor)
            ->put($this->url('admin.models.entries.update', ['model' => $this->posts->id, 'entry' => $entryId]), ['fields' => ['title' => 'Changed']])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $stored = $this->stored($entryId);
        $this->assertSame('Changed', $stored['title']);
        $this->assertSame($ada, $stored['author']);
        $this->assertSame([$ada], $stored['tags']);
    }

    public function test_a_crafted_value_for_a_hidden_link_is_ignored()
    {
        $ada = $this->author('Ada Lovelace');
        $grace = $this->author('Grace Hopper');
        $entryId = $this->newPost(['author' => $ada]);
        $editor = $this->member([], ['posts' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE, Ability::ENTRIES_CREATE]]);

        $this->actingAs($editor)
            ->put($this->url('admin.models.entries.update', ['model' => $this->posts->id, 'entry' => $entryId]), ['fields' => ['title' => 'Changed', 'author' => $grace, 'tags' => [$grace]]])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $stored = $this->stored($entryId);
        $this->assertSame($ada, $stored['author']);
        $this->assertArrayNotHasKey('tags', $stored);

        // A new entry takes nothing for it either.
        $this->actingAs($editor)
            ->post($this->url('admin.models.entries.store', ['model' => $this->posts->id]), ['fields' => ['title' => 'New', 'author' => $grace]])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // A list read can carry fields the entry never stored, so read the entry itself.
        $created = $this->stored(app(EntryManager::class)->page($this->posts)->rows[0]->id);
        $this->assertSame('New', $created['title']);
        $this->assertArrayNotHasKey('author', $created);
    }

    public function test_a_required_hidden_link_is_refused_on_create()
    {
        $articles = $this->modelLinking('articles', 'Articles', ['editor' => ['required' => true]]);
        $writer = $this->member([], ['articles' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_CREATE, Ability::ENTRIES_UPDATE]]);

        $this->actingAs($writer)
            ->post($this->url('admin.models.entries.store', ['model' => $articles->id]), ['fields' => ['title' => 'Draft']])
            ->assertSessionHasErrors(['fields.editor' => 'You need View entries on Authors to fill in Editor.']);
        $this->assertSame([], app(EntryManager::class)->page($articles)->rows);

        // Someone who can view Authors fills it in.
        $ada = $this->author('Ada Lovelace');
        $sighted = $this->member([], ['articles' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_CREATE], 'authors' => [Ability::ENTRIES_VIEW]]);
        $this->actingAs($sighted)
            ->post($this->url('admin.models.entries.store', ['model' => $articles->id]), ['fields' => ['title' => 'Draft', 'editor' => $ada]])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // An entry that is already empty stays empty when they edit it.
        $entryId = app(EntryManager::class)->create($articles, ['title' => 'Old']);
        $this->actingAs($writer)
            ->put($this->url('admin.models.entries.update', ['model' => $articles->id, 'entry' => $entryId]), ['fields' => ['title' => 'Edited']])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame('Edited', app(EntryManager::class)->get($articles, $entryId)?->fields['title']);
    }

    public function test_every_bad_id_gets_the_same_message()
    {
        $editor = User::factory()->create();
        $ada = $this->author('Ada Lovelace');
        $notAnAuthor = $this->newPost(['title' => 'Not an author']);

        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');
        app(CurrentSite::class)->set($other);
        $theirs = $this->create('authors', 'Authors');
        $secret = app(EntryManager::class)->create($theirs, ['title' => 'Secret']);
        app(CurrentSite::class)->set($this->site);

        $nothing = $notAnAuthor + 10000;
        $store = fn (array $fields) => $this->actingAs($editor)
            ->post($this->url('admin.models.entries.store', ['model' => $this->posts->id]), ['fields' => ['title' => 'Hello', ...$fields]]);

        // Missing, another model's, another site's.
        foreach ([$nothing, $notAnAuthor, $secret] as $id) {
            $store(['author' => $id])->assertSessionHasErrors(['fields.author' => "Entry {$id} can't be linked. Pick another or clear it."]);
        }

        // With several, the first id that fails is the one named.
        $store(['tags' => [$ada, $nothing, $secret]])->assertSessionHasErrors(['fields.tags' => "Entry {$nothing} can't be linked. Pick another or clear it."]);

        // And a model being deleted can't be linked to at all.
        $this->authors->update(['status' => SchemaModel::DELETING]);
        $store(['author' => $ada])->assertSessionHasErrors(['fields.author' => "Entry {$ada} can't be linked. Pick another or clear it."]);

        $this->assertCount(1, app(EntryManager::class)->page($this->posts)->rows, 'Only the entry made for the test is there.');
    }

    public function test_a_stored_id_deleted_for_good_does_not_block_an_edit()
    {
        $editor = User::factory()->create();
        $ada = $this->author('Ada Lovelace');
        $entryId = $this->newPost(['author' => $ada, 'tags' => [$ada]]);
        $other = $this->newPost([]);
        $entry = app(EntryManager::class)->get($this->authors, $ada);
        $this->assertNotNull($entry);
        app(EntryManager::class)->trash($this->authors, $entry);
        app(EntryManager::class)->delete($this->authors, $entry);

        // The form sends back what it was given, the stale id included.
        $this->actingAs($editor)
            ->put($this->url('admin.models.entries.update', ['model' => $this->posts->id, 'entry' => $entryId]), ['fields' => ['title' => 'Changed', 'author' => $ada, 'tags' => [$ada]]])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame(['Changed', $ada], [$this->stored($entryId)['title'], $this->stored($entryId)['author']]);

        // An entry that doesn't hold it can't pick it.
        $this->actingAs($editor)
            ->put($this->url('admin.models.entries.update', ['model' => $this->posts->id, 'entry' => $other]), ['fields' => ['title' => 'Changed', 'author' => $ada]])
            ->assertSessionHasErrors(['fields.author' => "Entry {$ada} can't be linked. Pick another or clear it."]);
    }

    public function test_a_lone_id_is_read_as_a_list_with_several()
    {
        $editor = User::factory()->create();
        $ada = $this->author('Ada Lovelace');

        // Stored as one id, from before "tags" allowed several.
        $entryId = $this->newPost(['tags' => $ada]);

        $this->actingAs($editor)
            ->get($this->url('admin.models.entries.edit', ['model' => $this->posts->id, 'entry' => $entryId]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('values.tags', $ada)
                ->where("links.{$ada}.label", 'Ada Lovelace')
                ->where("links.{$ada}.state", 'ok')
            );

        // The form sends it back as a list of one.
        $this->actingAs($editor)
            ->put($this->url('admin.models.entries.update', ['model' => $this->posts->id, 'entry' => $entryId]), ['fields' => ['title' => 'Hello', 'tags' => [$ada]]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$ada], $this->stored($entryId)['tags']);
    }

    /**
     * A model with a title, a body and relation fields to Authors, named by
     * their title.
     *
     * @param  array<string, array{multiple?: bool, required?: bool}>  $links  Field key => its settings.
     */
    private function modelLinking(string $slug, string $label, array $links): SchemaModel
    {
        $model = $this->create($slug, $label);
        $title = (string) $this->authors->fields()->where('key', 'title')->value('id');
        $schema = ModelSchema::fromModel($model);

        $result = $this->manager()->save($model, $schema->withFields([
            ...$schema->fields,
            ...array_map(fn (string $key) => new FieldSpec(
                (string) Str::uuid(),
                $key,
                Str::headline($key),
                'relation',
                ['link' => ['model' => $this->authors->id, 'label_field' => $title], 'multiple' => $links[$key]['multiple'] ?? false],
                required: $links[$key]['required'] ?? false,
            ), array_keys($links)),
        ]));
        $this->assertTrue($result->succeeded(), (string) $result->error());

        return $model;
    }

    private function author(string $name): int
    {
        return app(EntryManager::class)->create($this->authors, ['title' => $name]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function newPost(array $fields): int
    {
        return app(EntryManager::class)->create($this->posts, ['title' => 'Hello', ...$fields]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(int $entryId): array
    {
        $entry = app(EntryManager::class)->get($this->posts, $entryId);
        $this->assertNotNull($entry);

        return $entry->fields;
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
}
