<?php

namespace Tests\Feature\Frontend;

use App\Entries\EntryManager;
use App\Frontend\FrontendRoute;
use App\Frontend\ThemeManager;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * The public pages of a site that publishes two models, as a visitor
 * reaches them. It is one scenario, because a StarDust test costs set-up
 * and a drain however small. Requests go through the test site's own
 * domain, as in EntryControllerTest.
 */
class FrontendPagesTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    private string $themes;

    /**
     * StarDust commits on its own connection, so the promotions a test
     * queues outlive its rollback. Finishing them keeps later ticks idle.
     */
    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_the_site_shows_its_published_entries_and_nothing_else()
    {
        config(['starsystem.multisite' => true]);

        $this->useThemeWithTemplates();

        $pages = $this->model('pages');
        $posts = $this->model('posts');
        $entries = app(EntryManager::class);

        $entries->create($pages, ['slug' => 'home', 'title' => 'Welcome', 'body' => '<p>Home text</p>']);
        $entries->create($pages, ['slug' => 'about', 'title' => 'About us', 'body' => '<p onclick="steal()">We are <strong>small</strong>.</p><script>alert("rich")</script>']);
        $old = $entries->create($pages, ['slug' => 'old', 'title' => 'Old page', 'body' => '<p>Old text</p>']);
        $entries->create($posts, ['slug' => 'hello', 'title' => 'Hello post', 'published' => 1, 'body' => '<p>Post text</p>']);
        $entries->create($posts, ['slug' => 'draft', 'title' => 'Draft post', 'published' => 0, 'body' => '<p>Draft text</p>']);
        $gone = $entries->create($posts, ['slug' => 'gone', 'title' => 'Gone post', 'published' => 1, 'body' => '<p>Gone text</p>']);

        foreach (range(1, 12) as $n) {
            $entries->create($posts, ['slug' => sprintf('post-%02d', $n), 'title' => "Post {$n}", 'published' => 1, 'body' => '']);
        }

        $this->drain();

        $entries->trash($pages, $entries->get($pages, $old));
        $entries->trash($posts, $entries->get($posts, $gone));

        $this->store([
            $this->row($pages, ['path' => '', 'home_slug' => 'home', 'label' => 'Pages']),
            $this->row($posts, ['path' => 'blog', 'list' => true, 'published_field' => $this->field($posts, 'published'), 'label' => 'Journal']),
        ]);

        $site = $this->site->name;

        // The home page is the entry named as the home page.
        $home = $this->visit('/');
        $home->assertOk()->assertSee('<h1>Welcome</h1>', false)->assertSee('Home text', false);
        $home->assertSee('<title>'.$site.'</title>', false);
        $home->assertSee('<a href="'.$this->address('/').'" aria-current="page">Home</a>', false);
        $home->assertSee('<a href="'.$this->address('/blog').'">Journal</a>', false);
        $this->assertSame([], $home->headers->getCookies());

        // A model at the root address is reached by slug, and its rich text is cleaned.
        $about = $this->visit('/about');
        $about->assertOk()->assertSee('<h1>About us</h1>', false)->assertSee('<title>About us | '.$site.'</title>', false);
        $about->assertSee('<p>We are <strong>small</strong>.</p>', false);
        $about->assertDontSee('<script>', false)->assertDontSee('alert("rich")', false)->assertDontSee('onclick', false);
        $about->assertSee('<link rel="canonical" href="'.$this->address('/about').'">', false);

        // A model at an address is reached under it, and may have a template of its own.
        $this->visit('/blog/hello')->assertOk()->assertSee('POSTS ENTRY Hello post', false)->assertSee('Post text', false);

        // The list shows published entries, a page at a time.
        $list = $this->visit('/blog');
        $list->assertOk()->assertSee('POSTS LIST Journal', false)->assertSee('<a class="post-link" href="'.$this->address('/blog/post-12').'">Post 12</a>', false);
        $list->assertSee('<a href="'.$this->address('/blog').'" aria-current="page">Journal</a>', false);
        $list->assertDontSee('Draft post')->assertDontSee('Gone post');
        $this->assertSame(12, substr_count($list->getContent(), 'class="post-link"'));

        preg_match('~href="([^"]*/blog\?after=[^"]+)" rel="next"~', $list->getContent(), $next);
        $this->assertNotEmpty($next, 'The first page links to the next one.');

        $second = $this->get(html_entity_decode($next[1]));
        $second->assertOk()->assertSee('Hello post')->assertDontSee('Post 12');
        $this->assertSame(1, substr_count($second->getContent(), 'class="post-link"'));
        $this->assertStringNotContainsString('rel="next"', $second->getContent());

        // Nothing that is trashed, unpublished or unknown is found, and each says so with a 404.
        foreach (['/old', '/blog/gone', '/blog/draft', '/nothing', '/blog/nothing', '/hello', '/blog/Hello'] as $path) {
            $this->visit($path)->assertNotFound()->assertSee('Page not found')->assertDontSee('Old text')->assertDontSee('Draft text')->assertDontSee('Gone text');
        }

        // Another site on this install sees none of it.
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test', 'Other site');

        foreach (['/', '/about', '/blog', '/blog/hello'] as $path) {
            $response = $this->get('http://'.$other->domains[0].$path);

            $this->assertNotContains($response->getStatusCode(), [500]);
            $response->assertDontSee('About us')->assertDontSee('Hello post')->assertDontSee('Welcome');
        }

        $this->get('http://'.$other->domains[0].'/about')->assertNotFound()->assertSee('Page not found');
        $this->get('http://'.$other->domains[0].'/')->assertOk()->assertSee('Nothing has been published here yet.');
    }

    private function visit(string $path): TestResponse
    {
        return $this->get($this->address($path));
    }

    private function address(string $path): string
    {
        return 'http://'.$this->site->domains[0].($path === '/' ? '' : $path);
    }

    /**
     * Points the front end at a temporary themes folder holding the bundled
     * theme plus templates for the posts model alone.
     */
    private function useThemeWithTemplates(): void
    {
        $this->themes = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-pages-'.Str::random(10);
        File::copyDirectory(base_path('themes/default'), $this->themes.'/default');
        File::put($this->themes.'/default/views/entry-posts.blade.php', "@extends('theme::layout')\n@section('content')POSTS ENTRY {{ \$entry->title }} {!! \$entry->html('body') !!}@endsection");
        File::put($this->themes.'/default/views/list-posts.blade.php', "@extends('theme::layout')\n@section('content')POSTS LIST {{ \$route['label'] }}@foreach (\$entries as \$item)<a class=\"post-link\" href=\"{{ \$item->url }}\">{{ \$item->title }}</a>@endforeach @if (\$next)<a href=\"{{ \$next }}\" rel=\"next\">Older</a>@endif @endsection");

        config(['starsystem.themes.path' => $this->themes]);
        $this->app->forgetInstance(ThemeManager::class);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->themes));
    }

    /**
     * Writes rows into the current site's settings as they are.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): void
    {
        $this->site->update(['settings' => [...($this->site->settings ?? []), 'frontend' => ['routes' => $rows]]]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function row(SchemaModel $model, array $changes = []): array
    {
        return [
            ...(new FrontendRoute($model->id, 'blog', $this->field($model, 'slug'), $this->field($model, 'title'), null, false, null, $model->label))->toArray(),
            ...$changes,
        ];
    }

    private function field(SchemaModel $model, string $key): string
    {
        return SchemaField::query()->where('model_id', $model->id)->where('key', $key)->firstOrFail()->id;
    }

    /**
     * A model with slug (text, required, filterable), title, published
     * (checkbox, filterable) and body (rich text). Not drained.
     */
    private function model(string $slug): SchemaModel
    {
        $result = app(SchemaManager::class)->createModel(new ModelSchema($slug, Str::headline($slug), fields: [
            new FieldSpec((string) Str::uuid(), 'slug', 'Slug', 'text', required: true, filterable: true),
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
            new FieldSpec((string) Str::uuid(), 'published', 'Published', 'checkbox', filterable: true),
            new FieldSpec((string) Str::uuid(), 'body', 'Body', 'rich_text'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        return $result->model;
    }
}
