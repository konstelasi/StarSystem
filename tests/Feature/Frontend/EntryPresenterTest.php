<?php

namespace Tests\Feature\Frontend;

use App\Entries\EntryManager;
use App\Files\File;
use App\Frontend\EntryPresenter;
use App\Frontend\FrontendRoute;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * One scenario, because a StarDust test costs set-up and a drain however
 * small. Nothing here filters entries, so no field has to be indexed.
 */
class EntryPresenterTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    /**
     * StarDust commits on its own connection, so the work a test queues
     * outlives its rollback. Finishing it keeps later ticks idle.
     */
    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_an_entry_becomes_what_a_theme_receives()
    {
        $entries = app(EntryManager::class);

        $people = $this->model('people', [new FieldSpec((string) Str::uuid(), 'name', 'Name', 'text')]);
        $articles = $this->model('articles', [
            ...$this->basics(),
            new FieldSpec((string) Str::uuid(), 'published', 'Published', 'checkbox'),
        ]);
        $notes = $this->model('notes', [
            ...$this->basics(),
            new FieldSpec((string) Str::uuid(), 'cover', 'Cover', 'file'),
            new FieldSpec((string) Str::uuid(), 'related', 'Related', 'relation', ['link' => ['model' => $articles->id, 'label_field' => $this->field($articles, 'title')], 'multiple' => true]),
            new FieldSpec((string) Str::uuid(), 'author', 'Author', 'relation', ['link' => ['model' => $people->id, 'label_field' => $this->field($people, 'name')]]),
            new FieldSpec((string) Str::uuid(), 'kind', 'Kind', 'select', ['options' => ['kind' => 'static', 'options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']]]]),
            new FieldSpec((string) Str::uuid(), 'secret', 'Secret', 'hidden'),
        ]);

        $ayu = $entries->create($people, ['name' => 'Ayu']);
        $budi = $entries->create($people, ['name' => 'Budi']);
        $entries->trash($people, $entries->get($people, $budi));

        $hello = $entries->create($articles, ['slug' => 'hello', 'title' => 'Hello', 'published' => 1]);
        $second = $entries->create($articles, ['slug' => 'second-post', 'title' => 'Second post', 'published' => 1]);
        $draft = $entries->create($articles, ['slug' => 'draft', 'title' => 'Draft', 'published' => 0]);
        $gone = $entries->create($articles, ['slug' => 'gone', 'title' => 'Gone', 'published' => 1]);
        $entries->trash($articles, $entries->get($articles, $gone));

        $photo = $this->file('photo.png', 'image/png');
        $deleted = $this->file('old.pdf', 'application/pdf');
        $deleted->delete();

        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');
        $this->asSite($other);
        $foreign = $this->file('foreign.png', 'image/png');
        $this->asSite($this->site);

        $first = $entries->create($notes, [
            'slug' => 'first', 'title' => 'First note', 'cover' => $photo->uuid, 'kind' => 'b', 'secret' => 'internal',
            'related' => [$second, $draft, $hello, $gone, 999999], 'author' => $ayu,
        ]);
        $untitled = $entries->create($notes, ['slug' => 'untitled', 'title' => '', 'cover' => $deleted->uuid, 'author' => $budi]);
        $stranger = $entries->create($notes, ['slug' => 'stranger', 'title' => 'Stranger', 'cover' => $foreign->uuid]);

        $articlesRoute = new FrontendRoute($articles->id, 'blog', $this->field($articles, 'slug'), $this->field($articles, 'title'), $this->field($articles, 'published'), true, null, 'Blog');
        $notesRoute = new FrontendRoute($notes->id, 'notes', $this->field($notes, 'slug'), $this->field($notes, 'title'), null, false, null, 'Notes');
        $this->store([$articlesRoute->toArray(), $notesRoute->toArray()]);

        $presenter = app(EntryPresenter::class);
        $present = fn (int $id) => $presenter->present($entries->get($notes, $id), $notes, $notesRoute);

        // The basics.
        $note = $present($first);
        $this->assertSame($first, $note->id);
        $this->assertSame(url('notes/first'), $note->url);
        $this->assertSame('First note', $note->title);
        $this->assertSame('Beta', $note->text('kind'));

        // A file the site owns, named by its uuid.
        $this->assertSame($photo->url(), $note->file('cover')->url);
        $this->assertSame('photo.png', $note->file('cover')->name);
        $this->assertTrue($note->file('cover')->isImage);

        // Links keep the picked order and leave out whatever a visitor may not see.
        $this->assertSame(['Second post', 'Hello'], array_map(fn ($link) => $link->title, $note->links('related')));
        $this->assertSame([url('blog/second-post'), url('blog/hello')], array_map(fn ($link) => $link->url, $note->links('related')));
        $this->assertSame([$second, $hello], array_map(fn ($link) => $link->id, $note->links('related')));

        // A linked model with no address still names its entries, without a link.
        $this->assertSame('Ayu', $note->link('author')->title);
        $this->assertNull($note->link('author')->url);
        $this->assertSame('Ayu', $note->text('author'));

        // The title and address are shown by the page, and the hidden field is not offered.
        $this->assertSame(['cover', 'related', 'author', 'kind'], array_column($note->fields(), 'key'));
        $this->assertSame('internal', $note->raw('secret'));

        // A deleted file, another site's file and a trashed linked entry are all simply absent.
        $untitledNote = $present($untitled);
        $this->assertNull($untitledNote->file('cover'));
        $this->assertSame([], $untitledNote->links('author'));
        $this->assertSame('untitled', $untitledNote->title, 'An entry with no title is named by its address.');

        $this->assertNull($present($stranger)->file('cover'), "Another site's file is never shown.");

        // The home page entry lives at the root of the site.
        $homeRoute = new FrontendRoute($notes->id, '', $this->field($notes, 'slug'), $this->field($notes, 'title'), null, false, 'first', 'Notes');
        $this->assertSame(url('/'), $presenter->present($entries->get($notes, $first), $notes, $homeRoute)->url);
        $this->assertSame(url('untitled'), $presenter->present($entries->get($notes, $untitled), $notes, $homeRoute)->url);

        // A related entry whose model was left off the table has no link, and one that is gone has no entry.
        $this->store([$notesRoute->toArray()]);
        $elsewhere = app(EntryPresenter::class)->present($entries->get($notes, $first), $notes, $notesRoute);
        $this->assertSame([$second, $draft, $hello], array_map(fn ($link) => $link->id, $elsewhere->links('related')), 'Without an address, only a trashed entry is hidden.');
        $this->assertSame([null, null, null], array_map(fn ($link) => $link->url, $elsewhere->links('related')));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): void
    {
        $site = app(CurrentSite::class)->get();
        $site->update(['settings' => [...($site->settings ?? []), 'frontend' => ['routes' => $rows]]]);
    }

    private function field(SchemaModel $model, string $key): string
    {
        return SchemaField::query()->where('model_id', $model->id)->where('key', $key)->firstOrFail()->id;
    }

    private function file(string $name, string $mime): File
    {
        return File::create([
            'folder' => '', 'original_name' => $name, 'path' => 'tests/'.Str::random(8).'-'.$name, 'mime' => $mime,
            'size' => 1, 'sha256' => hash('sha256', $name),
        ]);
    }

    /**
     * @return list<FieldSpec>
     */
    private function basics(): array
    {
        return [
            new FieldSpec((string) Str::uuid(), 'slug', 'Slug', 'text'),
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
        ];
    }

    /**
     * @param  list<FieldSpec>  $fields
     */
    private function model(string $slug, array $fields): SchemaModel
    {
        $result = app(SchemaManager::class)->createModel(new ModelSchema($slug, Str::headline($slug), fields: $fields));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        return $result->model;
    }
}
