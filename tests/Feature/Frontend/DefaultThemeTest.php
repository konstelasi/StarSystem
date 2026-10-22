<?php

namespace Tests\Feature\Frontend;

use App\Frontend\HtmlCleaner;
use App\Frontend\PresentedEntry;
use App\Frontend\PresentedFile;
use App\Frontend\PresentedLink;
use App\Frontend\Theme;
use App\Frontend\ThemeManager;
use App\Frontend\ThemeManifest;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The bundled theme, rendered the way the front end renders any theme: its
 * views as the "theme" namespace, with the variables the contract lists.
 * Entries are built by hand, so nothing here touches the database.
 */
class DefaultThemeTest extends TestCase
{
    private const FILE = '0b4a3d4e-7f1b-4c47-9f6e-1c2d3e4f5a01';

    protected function setUp(): void
    {
        parent::setUp();

        View::replaceNamespace('theme', [base_path('themes/default/views')]);
    }

    public function test_the_manifest_is_valid_and_the_theme_is_found_in_the_themes_folder()
    {
        $manifest = ThemeManifest::load(base_path('themes/default'));

        $this->assertSame('Default', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);

        $manager = new ThemeManager(base_path('themes'), 'default');

        $this->assertSame([], $manager->problems());
        $this->assertSame(['default'], array_keys($manager->installed()));
        $this->assertSame('default', $manager->active(new Site(['theme' => null]))->name);
    }

    public function test_every_template_and_field_partial_the_contract_names_exists()
    {
        $theme = $this->theme();

        foreach (['layout', 'home', 'entry', 'list', '404', 'partials.entry', 'fields.default'] as $template) {
            $this->assertTrue($theme->has($template), "{$template} is missing");
        }

        foreach (['text', 'textarea', 'rich_text', 'file', 'datetime', 'url', 'email', 'select', 'radio', 'checkbox', 'checkboxes', 'relation', 'number', 'color'] as $type) {
            $this->assertTrue($theme->has('fields.'.$type), "fields.{$type} is missing");
        }
    }

    public function test_the_home_page_without_an_entry_is_the_holding_page()
    {
        $html = $this->render('home', ['entry' => null]);

        $this->assertStringContainsString('<h1>My site</h1>', $html);
        $this->assertStringContainsString('Nothing has been published here yet.', $html);
    }

    public function test_the_layout_frames_every_page()
    {
        $html = $this->render('home', ['entry' => null]);

        $this->assertStringContainsString('<html lang="en-GB">', $html);
        $this->assertStringContainsString('<title>My site</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="A site about things">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://site.test">', $html);
        $this->assertStringContainsString('<a class="skip" href="#main">Skip to content</a>', $html);
        $this->assertStringContainsString('<main id="main">', $html);
        $this->assertStringContainsString('href="'.url('_themes/default/theme.css').'?v=1.0.0"', $html);
        $this->assertStringContainsString('<a href="https://site.test/about">About</a>', $html);
        $this->assertStringContainsString('<a href="https://site.test/blog" aria-current="page">Blog</a>', $html);
        $this->assertMatchesRegularExpression('~<footer class="site-footer">\s*<p>My site</p>~', $html);
    }

    public function test_the_layout_leaves_out_what_it_has_nothing_for()
    {
        $html = $this->render('home', ['entry' => null, 'menu' => [], 'page' => ['title' => 'My site', 'description' => '', 'url' => '']]);

        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('name="description"', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_the_layout_escapes_what_comes_from_the_site()
    {
        $html = $this->render('home', [
            'entry' => null,
            'site' => ['name' => '<script>alert(1)</script>', 'locale' => 'en', 'url' => 'https://site.test'],
            'page' => ['title' => '"><script>alert(2)</script>', 'description' => '"><script>alert(3)</script>', 'url' => 'https://site.test'],
            'menu' => [['label' => '<b>x</b>', 'url' => 'https://site.test/x', 'current' => false]],
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function test_an_entry_page_shows_the_title_and_its_fields_in_order()
    {
        $html = $this->render('entry', ['entry' => $this->entry(), 'model' => ['slug' => 'posts', 'label' => 'Posts']]);

        $this->assertStringContainsString('<h1>Hello world</h1>', $html);
        $this->assertSame(1, substr_count($html, '<h1>'));

        $positions = array_map(fn (string $needle) => strpos($html, $needle), ['Summary', 'First paragraph', 'Posted on', 'Sunday']);
        $sorted = $positions;
        sort($sorted);

        $this->assertNotContains(false, $positions);
        $this->assertSame($sorted, $positions);
    }

    public function test_the_home_page_shows_its_entry_like_any_entry()
    {
        $html = $this->render('home', ['entry' => $this->entry()]);

        $this->assertStringContainsString('<h1>Hello world</h1>', $html);
        $this->assertStringContainsString('First paragraph', $html);
        $this->assertStringNotContainsString('Nothing has been published here yet.', $html);
    }

    public function test_text_is_escaped_and_rich_text_has_its_scripts_removed()
    {
        $html = $this->render('entry', ['entry' => $this->entry([
            'summary' => $this->field('textarea', "<script>alert('text')</script>\nsecond line", 'Summary'),
            'body' => $this->field('rich_text', '<p onclick="x()">First paragraph</p><script>alert("rich")</script><a href="javascript:alert(1)">bad</a>', 'Body'),
        ]), 'model' => ['slug' => 'posts', 'label' => 'Posts']]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert("rich")', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;text&#039;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<p>First paragraph</p>', $html);
    }

    public function test_each_kind_of_field_has_its_own_markup()
    {
        $html = $this->render('entry', ['entry' => $this->entry([
            'kind' => $this->field('select', 'b', 'Kind', ['b' => 'Beta']),
            'count' => $this->field('number', 3, 'Count'),
            'on' => $this->field('checkbox', 1, 'Featured'),
            'off' => $this->field('checkbox', 0, 'Pinned'),
            'mail' => $this->field('email', 'hi@site.test', 'Contact'),
            'site' => $this->field('url', 'https://x.test/?a=1', 'Website'),
            'hue' => $this->field('color', '#336699', 'Colour'),
            'when' => $this->field('datetime', '2026-10-22 08:00:00', 'Opens'),
            'snippet' => $this->field('code', '<b>x</b>', 'Snippet'),
            'tags' => $this->field('checkboxes', ['a', 'b'], 'Tags', ['a' => 'Alpha', 'b' => 'Beta']),
            'secret' => $this->field('hidden', 'internal', 'Secret'),
        ]), 'model' => ['slug' => 'posts', 'label' => 'Posts']]);

        $this->assertStringContainsString('<p>Beta</p>', $html);
        $this->assertStringContainsString('<p class="number">3</p>', $html);
        $this->assertStringContainsString('Featured', $html);
        $this->assertStringNotContainsString('Pinned', $html, 'A box that is not ticked is not shown.');
        $this->assertStringContainsString('<a href="mailto:hi@site.test">hi@site.test</a>', $html);
        $this->assertStringContainsString('<a href="https://x.test/?a=1" rel="noopener">', $html);
        $this->assertStringContainsString('<span class="swatch" style="background: #336699"></span>', $html);
        $this->assertStringContainsString('<time datetime="2026-10-22">22 October 2026</time>', $html);
        $this->assertStringContainsString('<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>', $html);
        $this->assertStringContainsString('<p>Alpha, Beta</p>', $html);
        $this->assertStringNotContainsString('internal', $html);
    }

    public function test_a_stored_address_or_colour_cannot_run_script_or_style()
    {
        $html = $this->render('entry', ['entry' => $this->entry([
            'site' => $this->field('url', 'javascript:alert(1)', 'Website'),
            'hue' => $this->field('color', 'red; position: fixed; inset: 0', 'Colour'),
        ]), 'model' => ['slug' => 'posts', 'label' => 'Posts']]);

        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('<p>javascript:alert(1)</p>', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('red; position: fixed; inset: 0', $html);
    }

    public function test_files_show_as_images_or_links()
    {
        $html = $this->render('entry', ['entry' => $this->entry(
            [
                'cover' => $this->field('file', self::FILE, 'Cover'),
                'docs' => $this->field('file', [self::FILE, '0b4a3d4e-7f1b-4c47-9f6e-1c2d3e4f5a02'], 'Documents'),
                'gone' => $this->field('file', '0b4a3d4e-7f1b-4c47-9f6e-ffffffffffff', 'Missing'),
            ],
            files: [
                self::FILE => new PresentedFile('https://site.test/files/a/cover.png', 'cover.png', 'image/png', 'A harbour at dusk'),
                '0b4a3d4e-7f1b-4c47-9f6e-1c2d3e4f5a02' => new PresentedFile('https://site.test/files/b/guide.pdf', 'guide.pdf', 'application/pdf'),
            ],
        ), 'model' => ['slug' => 'posts', 'label' => 'Posts']]);

        $this->assertStringContainsString('<img src="https://site.test/files/a/cover.png" alt="A harbour at dusk" loading="lazy">', $html);
        $this->assertStringContainsString('<a href="https://site.test/files/b/guide.pdf">guide.pdf</a>', $html);
        $this->assertStringNotContainsString('Missing', $html, 'A field whose files are all gone shows nothing.');
    }

    public function test_links_to_other_entries_are_links_only_when_they_have_an_address()
    {
        $html = $this->render('entry', ['entry' => $this->entry(
            ['authors' => $this->field('relation', [1, 2], 'Authors'), 'editor' => $this->field('relation', [9], 'Editor')],
            links: ['authors' => [new PresentedLink(1, 'Ayu', 'https://site.test/team/ayu'), new PresentedLink(2, 'Budi <b>', null)]],
        ), 'model' => ['slug' => 'posts', 'label' => 'Posts']]);

        $this->assertStringContainsString('<a href="https://site.test/team/ayu">Ayu</a>', $html);
        $this->assertMatchesRegularExpression('~<li>\s*Budi &lt;b&gt;\s*</li>~', $html);
        $this->assertStringNotContainsString('Editor', $html);
    }

    public function test_a_list_links_each_entry_and_the_next_page()
    {
        $html = $this->render('list', [
            'entries' => [$this->entry(), $this->entry([], title: 'Second <post>', url: 'https://site.test/blog/second')],
            'route' => ['label' => 'Blog', 'path' => 'blog'],
            'model' => ['slug' => 'posts', 'label' => 'Posts'],
            'next' => 'https://site.test/blog?after=abc',
        ]);

        $this->assertStringContainsString('<h1>Blog</h1>', $html);
        $this->assertStringContainsString('<a href="https://site.test/blog/hello">Hello world</a>', $html);
        $this->assertStringContainsString('<a href="https://site.test/blog/second">Second &lt;post&gt;</a>', $html);
        $this->assertStringContainsString('<time datetime="2026-10-22">22 October 2026</time>', $html);
        $this->assertStringContainsString('<a href="https://site.test/blog?after=abc" rel="next">Older</a>', $html);
    }

    public function test_the_last_page_of_a_list_has_no_older_link_and_an_empty_list_says_so()
    {
        $last = $this->render('list', ['entries' => [$this->entry()], 'route' => ['label' => 'Blog', 'path' => 'blog'], 'model' => ['slug' => 'posts', 'label' => 'Posts'], 'next' => null]);
        $empty = $this->render('list', ['entries' => [], 'route' => ['label' => 'Blog', 'path' => 'blog'], 'model' => ['slug' => 'posts', 'label' => 'Posts'], 'next' => null]);

        $this->assertStringNotContainsString('Older', $last);
        $this->assertStringContainsString('Nothing has been published here yet.', $empty);
        $this->assertStringNotContainsString('<ul class="entry-list">', $empty);
    }

    public function test_the_not_found_page_leads_home()
    {
        $html = $this->render('404', []);

        $this->assertStringContainsString('<h1>Page not found</h1>', $html);
        $this->assertStringContainsString('<a href="https://site.test">Go to the home page</a>', $html);
    }

    public function test_the_stylesheet_follows_the_visitors_colour_setting_and_loads_nothing_from_elsewhere()
    {
        $css = (string) file_get_contents(base_path('themes/default/assets/theme.css'));

        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
        $this->assertStringContainsString('color-scheme: light dark', $css);
        $this->assertDoesNotMatchRegularExpression('/@import|url\(|gradient/i', $css);
    }

    private function theme(): Theme
    {
        return new Theme('default', base_path('themes/default'), ThemeManifest::load(base_path('themes/default')));
    }

    /**
     * Renders a template with the variables the contract gives every
     * template, and $data on top.
     *
     * @param  array<string, mixed>  $data
     */
    private function render(string $template, array $data): string
    {
        return view('theme::'.$template, [
            'site' => ['name' => 'My site', 'locale' => 'en_GB', 'url' => 'https://site.test'],
            'theme' => $this->theme(),
            'menu' => [
                ['label' => 'About', 'url' => 'https://site.test/about', 'current' => false],
                ['label' => 'Blog', 'url' => 'https://site.test/blog', 'current' => true],
            ],
            'page' => ['title' => 'My site', 'description' => 'A site about things', 'url' => 'https://site.test'],
            ...$data,
        ])->render();
    }

    /**
     * @param  array<string, array{label: string, type: string, value: mixed, labels: array<string, string>}>  $fields  Replaces the default fields when given.
     * @param  array<string, PresentedFile>  $files
     * @param  array<string, list<PresentedLink>>  $links
     */
    private function entry(array $fields = [], array $files = [], array $links = [], string $title = 'Hello world', string $url = 'https://site.test/blog/hello'): PresentedEntry
    {
        return new PresentedEntry(
            id: 1,
            url: $url,
            title: $title,
            createdAt: CarbonImmutable::parse('2026-10-22 10:00:00'),
            fields: [
                'title' => $this->field('text', $title, 'Title'),
                'slug' => $this->field('text', 'hello', 'Slug'),
                ...($fields === [] ? [
                    'summary' => $this->field('textarea', 'A short summary.', 'Summary'),
                    'body' => $this->field('rich_text', '<p>First paragraph</p>', 'Body'),
                    'posted' => $this->field('datetime', '2026-10-22 00:00:00', 'Posted on'),
                    'day' => $this->field('select', 'sun', 'Day', ['sun' => 'Sunday']),
                ] : $fields),
            ],
            cleaner: new HtmlCleaner,
            leave: ['title', 'slug'],
            files: $files,
            links: $links,
        );
    }

    /**
     * @param  array<string, string>  $labels
     * @return array{label: string, type: string, value: mixed, labels: array<string, string>}
     */
    private function field(string $type, mixed $value, string $label, array $labels = []): array
    {
        return ['label' => $label, 'type' => $type, 'value' => $value, 'labels' => $labels];
    }
}
