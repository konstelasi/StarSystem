<?php

namespace Tests\Feature\Frontend;

use App\Entries\EntryManager;
use App\Frontend\FrontendMatch;
use App\Frontend\FrontendRoute;
use App\Frontend\FrontendRouter;
use App\Frontend\PublicEntries;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use StarDust\Exception\FieldNotFilterableException;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * A StarDust test costs set-up and a drain however small, so each test here
 * checks one whole scenario. The table is written straight into the site's
 * settings, since saving it is FrontendSettingsTest's job.
 */
class FrontendRouterTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    /**
     * StarDust commits on its own connection, so the promotions a test
     * queues outlive its rollback. Finishing them keeps later ticks idle.
     */
    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_addresses_lead_to_public_entries_only()
    {
        $pages = $this->model('pages');
        $posts = $this->model('posts');

        $home = $this->entry($pages, 'home', 'Welcome');
        $about = $this->entry($pages, 'about', 'About us');
        $old = $this->entry($pages, 'old', 'Old page');
        $hello = $this->entry($posts, 'hello', 'Hello', published: true);
        $draft = $this->entry($posts, 'draft', 'Draft', published: false);
        $gone = $this->entry($posts, 'gone', 'Gone', published: true);

        foreach (range(1, 12) as $n) {
            $this->entry($posts, sprintf('post-%02d', $n), "Post {$n}", published: true);
        }

        // The trash flag is indexed by the tick, as is every filterable field.
        $this->drain();

        $entries = app(EntryManager::class);
        $entries->trash($pages, $entries->get($pages, $old));
        $entries->trash($posts, $entries->get($posts, $gone));

        $this->store([
            $this->row($pages, ['path' => '', 'home_slug' => 'home']),
            $this->row($posts, ['path' => 'blog', 'list' => true, 'published_field' => $this->field($posts, 'published')]),
        ]);

        // The root address is the home entry, and the same entry can be reached by its slug.
        $match = $this->router()->resolve('');
        $this->assertSame(FrontendMatch::HOME, $match->kind);
        $this->assertSame($home, $match->entry?->id);
        $this->assertSame($pages->id, $match->model?->id);
        $this->assertSame($home, $this->router()->resolve('/')->entry?->id);

        // A model at the root address is reached by slug alone.
        $match = $this->router()->resolve('about');
        $this->assertSame(FrontendMatch::ENTRY, $match->kind);
        $this->assertSame($about, $match->entry->id);
        $this->assertSame($pages->id, $match->route->modelId);
        $this->assertSame($about, $this->router()->resolve('/about/')->entry?->id);

        // A model at an address is reached under it.
        $match = $this->router()->resolve('blog/hello');
        $this->assertSame(FrontendMatch::ENTRY, $match->kind);
        $this->assertSame($hello, $match->entry->id);
        $this->assertSame($posts->id, $match->model->id);

        // A trashed entry, an unpublished one and an unknown one are not found.
        foreach (['old', 'blog/gone', 'blog/draft', 'nothing', 'blog/nothing', 'about/x'] as $path) {
            $this->assertSame(FrontendMatch::NONE, $this->router()->resolve($path)->kind, "{$path} should not be found");
        }

        // A model with no row has no pages, even by slug.
        $this->assertSame(FrontendMatch::NONE, $this->router()->resolve('hello')->kind);

        // Anything that cannot be an address or a slug is not asked of StarDust at all.
        foreach (['About', 'a/b/c', 'blog/hello/extra', 'a//b', 'caf'."\u{e9}", 'two words', str_repeat('a', 121)] as $path) {
            $this->assertSame(FrontendMatch::NONE, $this->router()->resolve($path)->kind, "{$path} should not be found");
        }

        // The list shows the published entries, newest first, a page at a time.
        $first = $this->router()->resolve('blog');
        $this->assertSame(FrontendMatch::LIST, $first->kind);
        $this->assertSame($posts->id, $first->route->modelId);
        $this->assertCount(12, $first->page->rows);
        $this->assertSame('post-12', $first->page->rows[0]->fields['slug']);
        $this->assertSame('post-01', $first->page->rows[11]->fields['slug']);
        $this->assertIsString($first->next);

        $second = $this->router()->resolve('blog', $first->next);
        $this->assertCount(1, $second->page->rows);
        $this->assertSame($hello, $second->page->rows[0]->id);
        $this->assertNull($second->next);

        // A cursor StarDust did not issue gives the first page.
        $this->assertSame('post-12', $this->router()->resolve('blog', 'not-a-cursor')->page->rows[0]->fields['slug']);
        $this->assertSame(FrontendMatch::LIST, $this->router()->resolve('blog/')->kind);

        $this->checkPublicEntries($posts, $hello, $draft, $gone);
    }

    public function test_an_address_with_no_list_is_not_a_page_and_a_missing_table_shows_only_the_home_page()
    {
        $this->assertSame(FrontendMatch::HOME, $this->router()->resolve('')->kind);
        $this->assertNull($this->router()->resolve('')->entry);
        $this->assertSame(FrontendMatch::NONE, $this->router()->resolve('about')->kind);

        $posts = $this->model('posts');
        $this->store([$this->row($posts, ['path' => 'blog', 'list' => false])]);

        // No root row, so the home page is empty, and "blog" has no list.
        $this->assertSame(FrontendMatch::HOME, $this->router()->resolve('')->kind);
        $this->assertNull($this->router()->resolve('')->entry);
        $this->assertSame(FrontendMatch::NONE, $this->router()->resolve('blog')->kind);
    }

    public function test_an_address_whose_field_is_not_indexed_yet_is_not_found_and_does_not_fail()
    {
        Exceptions::fake();

        $pages = $this->model('pages');
        $this->entry($pages, 'about', 'About us');
        $this->store([$this->row($pages, ['path' => ''])]);

        $this->assertSame(FrontendMatch::NONE, $this->router()->resolve('about')->kind);
        // Until the tick gives the field a slot, StarDust calls it not filterable.
        Exceptions::assertReported(FieldNotFilterableException::class);
    }

    public function test_another_sites_entry_with_the_same_slug_is_never_returned()
    {
        $here = $this->model('pages');
        $hereId = $this->entry($here, 'about', 'About here');
        $hereRow = $this->row($here, ['path' => '']);

        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');
        $this->asSite($other);
        $there = $this->model('pages');
        $thereId = $this->entry($there, 'about', 'About there');
        $this->store([$this->row($there, ['path' => ''])]);

        $third = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->drain();

        $this->asSite($this->site);
        $this->store([$hereRow]);

        $this->assertSame($hereId, $this->router()->resolve('about')->entry?->id);

        $this->asSite($other);
        $this->assertSame($thereId, $this->router()->resolve('about')->entry?->id);

        // A site with no table of its own shows nothing, even naming another site's model.
        $this->asSite($third);
        $this->assertSame(FrontendMatch::NONE, $this->router()->resolve('about')->kind);

        $this->store([$hereRow]);
        $this->assertSame(FrontendMatch::NONE, $this->router()->resolve('about')->kind);
    }

    /**
     * The rules the entry form needs, on the posts model of the first test.
     */
    private function checkPublicEntries(SchemaModel $posts, int $hello, int $draft, int $gone): void
    {
        $public = app(PublicEntries::class);
        $entries = app(EntryManager::class);
        $route = FrontendRoute::fromArray($this->row($posts, ['path' => 'blog', 'published_field' => $this->field($posts, 'published')]));
        $withoutSwitch = FrontendRoute::fromArray($this->row($posts, ['path' => 'blog']));

        // A slug is taken by any entry not in the trash, published or not.
        $this->assertTrue($public->slugTaken($posts, $route, 'hello', null));
        $this->assertFalse($public->slugTaken($posts, $route, 'hello', $hello), 'An entry does not clash with itself.');
        $this->assertTrue($public->slugTaken($posts, $route, 'draft', null));
        $this->assertFalse($public->slugTaken($posts, $route, 'gone', null), 'A slug in the trash can be used again.');
        $this->assertFalse($public->slugTaken($posts, $route, 'nothing', null));

        // The same rule applied to an entry already read.
        $this->assertTrue($public->isVisible($entries->get($posts, $hello), $posts, $route));
        $this->assertFalse($public->isVisible($entries->get($posts, $draft), $posts, $route));
        $this->assertFalse($public->isVisible($entries->get($posts, $gone), $posts, $route));
        $this->assertTrue($public->isVisible($entries->get($posts, $draft), $posts, $withoutSwitch), 'Without a published field every entry not in the trash is public.');
        $this->assertFalse($public->isVisible($entries->get($posts, $gone), $posts, $withoutSwitch));

        // StarDust compares text without regard to case. The exact slug is the one that counts.
        $this->assertNotNull($public->bySlug($posts, $route, 'hello'));
        $this->assertNull($public->bySlug($posts, $route, 'HELLO'));

        // With no published field named, a draft is public.
        $this->assertNotNull($public->bySlug($posts, $withoutSwitch, 'draft'));
    }

    private function router(): FrontendRouter
    {
        return $this->app->make(FrontendRouter::class);
    }

    /**
     * Writes rows into the current site's settings as they are.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): void
    {
        $site = app(CurrentSite::class)->get();
        $site->update(['settings' => [...($site->settings ?? []), 'frontend' => ['routes' => $rows]]]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function row(SchemaModel $model, array $changes = []): array
    {
        return [
            'model_id' => $model->id,
            'path' => 'blog',
            'slug_field' => $this->field($model, 'slug'),
            'title_field' => $this->field($model, 'title'),
            'published_field' => null,
            'list' => false,
            'home_slug' => null,
            'label' => $model->label,
            ...$changes,
        ];
    }

    private function field(SchemaModel $model, string $key): string
    {
        return SchemaField::query()->where('model_id', $model->id)->where('key', $key)->firstOrFail()->id;
    }

    /**
     * A model with slug (text, required, filterable), title and published
     * (checkbox, filterable). Not drained.
     */
    private function model(string $slug): SchemaModel
    {
        $result = app(SchemaManager::class)->createModel(new ModelSchema($slug, Str::headline($slug), fields: [
            new FieldSpec((string) Str::uuid(), 'slug', 'Slug', 'text', required: true, filterable: true),
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
            new FieldSpec((string) Str::uuid(), 'published', 'Published', 'checkbox', filterable: true),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        return $result->model;
    }

    private function entry(SchemaModel $model, string $slug, string $title, ?bool $published = null): int
    {
        return app(EntryManager::class)->create($model, [
            'slug' => $slug,
            'title' => $title,
            ...($published === null ? [] : ['published' => $published ? 1 : 0]),
        ]);
    }
}
